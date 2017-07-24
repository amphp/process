<?php declare(strict_types=1);

namespace Amp\Process\Internal\Windows;

use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Loop;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\ProcessException;

final class SocketConnector
{
    const SERVER_SOCKET_URI = 'tcp://127.0.0.1:0';
    const SECURITY_TOKEN_SIZE = 16;
    const CONNECT_TIMEOUT = 100;

    /** @var resource */
    private $server;

    /** @var PendingSocketClient[] */
    private $pendingClients = [];

    /** @var Handle[] */
    private $pendingProcesses = [];

    /** @var string */
    public $address;

    /** @var int */
    public $port;

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
        $this->port = (int)$this->port;

        Loop::unreference(Loop::onReadable($this->server, [$this, 'onServerSocketReadable']));
    }


    private function failClientHandshake($socket, int $code): void
    {
        \fwrite($socket, \chr(SignalCode::HANDSHAKE_ACK) . \chr($code));
        \fclose($socket);
        unset($this->pendingClients[(int)$socket]);
    }

    private function failHandleStart(Handle $handle, string $message, ...$args)
    {
        Loop::cancel($handle->connectTimeoutWatcher);
        unset($this->pendingProcesses[$handle->wrapperPid]);

        foreach ($handle->sockets as $socket) {
            \fclose($socket);
        }

        $handle->startDeferred->fail(new ProcessException(\vsprintf($message, $args)));
    }

    /**
     * Read data from a client socket
     *
     * This method cleans up internal state as appropriate. Returns null if the read fails or needs to be repeated.
     *
     * @param resource $socket
     * @param int $length
     * @param PendingSocketClient $state
     * @return string|null
     */
    private function readDataFromPendingClient($socket, int $length, PendingSocketClient $state)
    {
        $data = \fread($socket, $length);

        if ($data === false || $data === '') {
            \fclose($socket);
            Loop::cancel($state->readWatcher);
            Loop::cancel($state->timeoutWatcher);
            unset($this->pendingClients[(int)$socket]);
            return null;
        }

        $data = $state->recievedDataBuffer . $data;

        if (\strlen($data) < $length) {
            $state->recievedDataBuffer = $data;
            return null;
        }

        $state->recievedDataBuffer = '';

        Loop::cancel($state->readWatcher);
        Loop::cancel($state->timeoutWatcher);

        return $data;
    }

    public function onReadable_Handshake($watcher, $socket) {
        $socketId = (int)$socket;
        $pendingClient = $this->pendingClients[$socketId];

        if (null === $data = $this->readDataFromPendingClient($socket, self::SECURITY_TOKEN_SIZE + 6, $pendingClient)) {
            return;
        }

        $packet = \unpack('Csignal/Npid/Cstream_id/a*client_token', $data);

        // validate the client's handshake
        if ($packet['signal'] !== SignalCode::HANDSHAKE) {
            $this->failClientHandshake($socket, HandshakeStatus::SIGNAL_UNEXPECTED);
            return;
        }

        if ($packet['stream_id'] > 2) {
            $this->failClientHandshake($socket, HandshakeStatus::INVALID_STREAM_ID);
            return;
        }

        if (!isset($this->pendingProcesses[$packet['pid']])) {
            $this->failClientHandshake($socket, HandshakeStatus::INVALID_PROCESS_ID);
            return;
        }

        $handle = $this->pendingProcesses[$packet['pid']];

        if (isset($handle->sockets[$packet['stream_id']])) {
            $this->failClientHandshake($socket, HandshakeStatus::DUPLICATE_STREAM_ID);
            $this->failHandleStart($handle, "Received duplicate socket for stream #%d", $packet['stream_id']);
            return;
        }

        if ($packet['client_token'] !== $handle->securityTokens[$packet['stream_id']]) {
            $this->failClientHandshake($socket, HandshakeStatus::INVALID_CLIENT_TOKEN);
            $this->failHandleStart($handle, "Invalid client security token for stream #%d", $packet['stream_id']);
            return;
        }

        $ackData = \chr(SignalCode::HANDSHAKE_ACK) . \chr(HandshakeStatus::SUCCESS)
            . $handle->securityTokens[$packet['stream_id'] + 3];

        // Unless we set the security token size so high that it won't fit in the
        // buffer, this probably shouldn't ever happen unless something has gone wrong
        if (\fwrite($socket, $ackData) !== self::SECURITY_TOKEN_SIZE + 2) {
            unset($this->pendingClients[$socketId]);
            return;
        }

        $pendingClient->pid = $packet['pid'];
        $pendingClient->streamId = $packet['stream_id'];

        $pendingClient->readWatcher = Loop::onReadable($socket, [$this, 'onReadable_HandshakeAck']);
    }

    public function onReadable_HandshakeAck($watcher, $socket)
    {
        $socketId = (int)$socket;
        $pendingClient = $this->pendingClients[$socketId];

        // can happen if the start promise was failed
        if (!isset($this->pendingProcesses[$pendingClient->pid])) {
            \fclose($socket);
            Loop::cancel($watcher);
            Loop::cancel($pendingClient->timeoutWatcher);
            unset($this->pendingClients[$socketId]);
            return;
        }

        if (null === $data = $this->readDataFromPendingClient($socket, 2, $pendingClient)) {
            return;
        }

        unset($this->pendingClients[$socketId]);
        $handle = $this->pendingProcesses[$pendingClient->pid];

        $packet = \unpack('Csignal/Cstatus', $data);

        if ($packet['signal'] !== SignalCode::HANDSHAKE_ACK || $packet['status'] !== HandshakeStatus::SUCCESS) {
            $this->failHandleStart(
                $handle, "Client rejected handshake with code %d for stream #%d",
                $packet['status'], $pendingClient->streamId
            );
            return;
        }

        $handle->sockets[$pendingClient->streamId] = $socket;

        if (count($handle->sockets) === 3) {
            $pendingClient->readWatcher = Loop::onReadable($handle->sockets[0], [$this, 'onReadable_ChildPid'], $handle);
        }
    }

    public function onReadable_ChildPid($watcher, $socket, Handle $handle)
    {
        Loop::cancel($watcher);
        Loop::cancel($handle->connectTimeoutWatcher);

        $data = \fread($socket, 5);

        if ($data === false || $data === '') {
            $this->failHandleStart($handle, 'Failed to read PID from wrapper');
            return;
        }

        if (\strlen($data) !== 5) {
            $this->failHandleStart(
                $handle, 'Failed to read PID from wrapper: Recieved %d of 5 expected bytes', \strlen($data)
            );
            return;
        }

        $packet = \unpack('Csignal/Npid', $data);

        if ($packet['signal'] !== SignalCode::CHILD_PID) {
            $this->failHandleStart(
                $handle, "Failed to read PID from wrapper: Unexpected signal code %d", $packet['signal']
            );
            return;
        }

        $handle->status = ProcessStatus::RUNNING;
        $handle->pid = $packet['pid'];
        $handle->stdin = new ResourceOutputStream($handle->sockets[0]);
        $handle->stdout = new ResourceInputStream($handle->sockets[1]);
        $handle->stderr = new ResourceInputStream($handle->sockets[2]);

        $handle->exitCodeWatcher = Loop::onReadable($handle->sockets[0], [$this, 'onReadable_ExitCode'], $handle);
        Loop::unreference($handle->exitCodeWatcher);

        unset($this->pendingProcesses[$handle->wrapperPid]);
        $handle->startDeferred->resolve($handle);
    }

    public function onReadable_ExitCode($watcher, $socket, Handle $handle)
    {
        $handle->exitCodeWatcher = null;
        Loop::cancel($watcher);

        $data = \fread($socket, 5);

        if ($data === false || \strlen($data) !== 5) {
            var_dump($data, \stream_get_contents($handle->wrapperStderrPipe));
            $handle->status = ProcessStatus::ENDED;
            $handle->endDeferred->fail(new ProcessException('Failed to read exit code from wrapper'));
            return;
        }

        $packet = \unpack('Csignal/Ncode', $data);

        if ($packet['signal'] !== SignalCode::EXIT_CODE) {
            $this->failHandleStart(
                $handle, "Failed to read exit code from wrapper: Unexpected signal code %d", $packet['signal']
            );
            return;
        }

        $handle->status = ProcessStatus::ENDED;
        $handle->endDeferred->resolve($packet['code']);
    }

    public function onClientSocketConnectTimeout($watcher, $socket) {
        $id = (int)$socket;

        Loop::cancel($this->pendingClients[$id]->readWatcher);
        unset($this->pendingClients[$id]);

        \fclose($socket);
    }

    public function onServerSocketReadable() {
        $socket = \stream_socket_accept($this->server);

        if (!\stream_set_blocking($socket, false)) {
            throw new \Error("Failed to set client socket to non-blocking mode");
        }

        $pendingClient = new PendingSocketClient;
        $pendingClient->readWatcher = Loop::onReadable($socket, [$this, 'onReadable_Handshake']);
        $pendingClient->timeoutWatcher = Loop::delay(self::CONNECT_TIMEOUT, [$this, 'onClientSocketConnectTimeout'], $socket);

        $this->pendingClients[(int)$socket] = $pendingClient;
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

    public function registerPendingProcess(Handle $handle)
    {
        $handle->connectTimeoutWatcher = Loop::delay(self::CONNECT_TIMEOUT, [$this, 'onProcessConnectTimeout'], $handle);

        $this->pendingProcesses[$handle->wrapperPid] = $handle;
    }
}