<?php declare(strict_types=1);

namespace Amp\Process\Internal\Windows;

use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Loop;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\ProcessException;
use Amp\Promise;

final class Runner implements ProcessRunner
{
    const FD_SPEC = [
        ["pipe", "r"], // stdin
        ["pipe", "w"], // stdout
        ["pipe", "w"], // stderr
        ["pipe", "w"], // exit code pipe
    ];
    const SERVER_SOCKET_URI = 'tcp://127.0.0.1:0';
    const WRAPPER_EXE_PATH = __DIR__ . '\\..\\bin\\windows\\ProcessWrapper.exe';
    const SECURITY_TOKEN_SIZE = 16;
    const CONNECT_TIMEOUT = 100;

    const HANDSHAKE_SUCCESS = 0;
    const HANDSHAKE_ERROR_INVALID_STREAM_ID = 1;
    const HANDSHAKE_ERROR_INVALID_PROCESS_ID = 2;
    const HANDSHAKE_ERROR_DUPLICATE_STREAM_ID = 3;
    const HANDSHAKE_ERROR_INVALID_CLIENT_TOKEN = 4;

    private $server;
    private $address;
    private $port;

    private $pendingClients = [];

    /**
     * @var Handle[]
     */
    private $pendingProcesses = [];

    private function makeCommand(string $command, string $workingDirectory = null): string {
        $result = sprintf(
            '"%s" --address=%s --port=%d --token-size=%d',
            self::WRAPPER_EXE_PATH,
            $this->address,
            $this->port,
            self::SECURITY_TOKEN_SIZE
        );

        if ($workingDirectory !== null) {
            $result .= ' "--cwd=' . \rtrim($workingDirectory, '\\') . '"';
        }

        $result .= ' ' . $command;

        return $result;
    }

    private function failClientHandshake($client, int $code): void
    {
        fwrite($client, chr($code));
        fclose($client);
    }

    public function __construct()     {
        $this->server = \stream_socket_server(
            self::SERVER_SOCKET_URI,
            $errNo, $errStr,
            \STREAM_SERVER_LISTEN | \STREAM_SERVER_BIND
        );

        if (!$this->server) {
            throw new \Error("Failed to create TCP server socket for process wrapper: {$errNo}: {$errStr}");
        }

        if (!\stream_set_blocking($this->server, false)) {
            throw new \Error("Failed to set server socket to non-blocking mode");
        }

        list($this->address, $this->port) = \explode(':', \stream_socket_get_name($this->server, false));

        Loop::onReadable($this->server, [$this, 'onServerSocketReadable']);
    }

    public function onNewClientSocketReadable($watcher, $client) {
        $id = (int)$client;

        $data = \fread($client, self::SECURITY_TOKEN_SIZE + 5);

        if ($data === false || $data === '') {
            \fclose($client);
            Loop::cancel($watcher);
            Loop::cancel($this->pendingClients[$id]['timeout']);
            unset($this->pendingClients[$id]);
            return;
        }

        $data = $this->pendingClients[$id]['data'] . $data;

        if (\strlen($data) < self::SECURITY_TOKEN_SIZE + 5) {
            $this->pendingClients[$id]['data'] = $data;
            return;
        }

        Loop::cancel($watcher);
        Loop::cancel($this->pendingClients[$id]['timeout']);

        $packet = \unpack('Npid/Cstream_id/a*token', $data);

        // validate the client's handshake
        if ($packet['stream_id'] > 2) {
            self::failClientHandshake($client, self::HANDSHAKE_ERROR_INVALID_STREAM_ID);
            return;
        }

        if (!isset($this->$pendingProcesses[$packet['pid']])) {
            self::failClientHandshake($client, self::HANDSHAKE_ERROR_INVALID_PROCESS_ID);
            return;
        }

        $handle = $this->pendingProcesses[$packet['pid']];

        if (isset($handle->sockets[$packet['stream_id']])) {
            self::failClientHandshake($client, self::HANDSHAKE_ERROR_DUPLICATE_STREAM_ID);
            return;
        }

        if ($packet['token'] !== $handle->securityTokens[$packet['stream_id']]) {
            self::failClientHandshake($client, self::HANDSHAKE_ERROR_INVALID_CLIENT_TOKEN);
            return;
        }

        // Everything is fine, send our handshake back
        $handle->sockets[$packet['stream_id']] = $client;
        \fwrite($client, chr(self::HANDSHAKE_SUCCESS) . $handle->securityTokens[$packet['stream_id'] + 3]);

        if (count($handle->sockets) < 3) {
            return;
        }

        unset($this->pendingProcesses[$packet['pid']]);
        Loop::cancel($handle->connectTimeoutWatcher);

        $handle->stdin = new ResourceOutputStream($handle->sockets[0]);
        $handle->stdout = new ResourceInputStream($handle->sockets[1]);
        $handle->stderr = new ResourceInputStream($handle->sockets[2]);

        // todo: get real child PID from sockets[0], set up term watchers

        $handle->startDeferred->resolve($handle);
    }

    public function onNewClientSocketTimeout($watcher, $client) {
        \stream_socket_shutdown($client, \STREAM_SHUT_RDWR);

        $id = (int)$client;

        Loop::cancel($this->pendingClients[$id]['read']);
        unset($this->pendingClients[$id]);

        \fclose($client);
    }

    public function onServerSocketReadable() {
        while ($client = \stream_socket_accept($this->server)) {
            if (!\stream_set_blocking($client, false)) {
                throw new \Error("Failed to set client socket to non-blocking mode");
            }

            $this->pendingClients[(int)$client] = [
                'read' => Loop::onReadable($client, [$this, 'onNewClientSocketReadable']),
                'timeout' => Loop::delay(self::CONNECT_TIMEOUT, [$this, 'onNewClientSocketTimeout'], $client),
                'data' => '',
            ];
        }
    }

    public function onProcessConnectTimeout($watcher, Handle $handle) {
        $status = \proc_get_status($handle->proc);

        $error = null;
        if (!$status['running']) {
            $error = \stream_get_contents($handle->wrapperStderrPipe);
        }
        $error = $error ?: 'Process did not connect to server before timeout elapsed';

        \fclose($handle->wrapperStderrPipe);
        \proc_close($handle->proc);
        foreach ($handle->sockets as $socket) {
            \fclose($socket);
        }

        $handle->startDeferred->fail(new ProcessException(\trim($error)));
    }

    /**
     * {@inheritdoc}
     */
    public function start(string $command, string $cwd = null, array $env = [], array $options = []): Promise
    {
        $command = $this->makeCommand($command, $cwd);

        $options['bypass_shell'] = true;

        $handle = new Handle;
        $handle->proc = @\proc_open($command, self::FD_SPEC, $pipes, $cwd ?: null, $env ?: null, $options);

        if (!\is_resource($handle->proc)) {
            $message = "Could not start process";
            if ($error = \error_get_last()) {
                $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
            }
            throw new ProcessException($message);
        }

        $status = \proc_get_status($handle->proc);

        if (!$status) {
            \proc_close($handle->proc);
            throw new ProcessException("Could not get process status");
        }

        $securityTokens = \random_bytes(self::SECURITY_TOKEN_SIZE * 6);
        $written = \fwrite($pipes[0], $securityTokens);

        \fclose($pipes[0]);
        \fclose($pipes[1]);

        if ($written !== self::SECURITY_TOKEN_SIZE * 6) {
            \fclose($pipes[2]);
            \proc_close($handle->proc);

            throw new ProcessException("Could not send security tokens to process wrapper");
        }

        $handle->securityTokens = \str_split($securityTokens, self::SECURITY_TOKEN_SIZE);
        $handle->wrapperPid = $status['pid'];
        $handle->wrapperStderrPipe = $pipes[2];
        $handle->connectTimeoutWatcher = Loop::delay(self::CONNECT_TIMEOUT, [$this, 'onProcessConnectTimeout'], $handle);

        $this->pendingProcesses[$handle->wrapperPid] = $handle;

        return $handle->startDeferred->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function join(ProcessHandle $handle): Promise
    {
        // TODO: Implement join() method.
    }

    /**
     * {@inheritdoc}
     */
    public function kill(ProcessHandle $handle)
    {
        // TODO: Implement kill() method.
    }

    /**
     * {@inheritdoc}
     */
    public function signal(ProcessHandle $handle, int $signo)
    {
        throw new ProcessException('Signals are not supported on Windows');
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(ProcessHandle $handle)
    {
        // TODO: Implement destroy() method.
    }
}
