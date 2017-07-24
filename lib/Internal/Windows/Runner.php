<?php declare(strict_types=1);

namespace Amp\Process\Internal\Windows;

use Amp\Loop;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\ProcessException;
use Amp\Promise;
use const Amp\Process\BIN_DIR;

final class Runner implements ProcessRunner
{
    const FD_SPEC = [
        ["pipe", "r"], // stdin
        ["pipe", "w"], // stdout
        ["pipe", "w"], // stderr
        ["pipe", "w"], // exit code pipe
    ];
    const WRAPPER_EXE_PATH = PHP_INT_SIZE === 8
        ? BIN_DIR . '\\windows\\ProcessWrapper64.exe'
        : BIN_DIR . '\\windows\\ProcessWrapper.exe';

    private $socketConnector;

    private function makeCommand(string $command, string $workingDirectory): string {
        $result = sprintf(
            '"%s" --address=%s --port=%d --token-size=%d',
            self::WRAPPER_EXE_PATH,
            $this->socketConnector->address,
            $this->socketConnector->port,
            SocketConnector::SECURITY_TOKEN_SIZE
        );

        if ($workingDirectory !== '') {
            $result .= ' "--cwd=' . \rtrim($workingDirectory, '\\') . '"';
        }

        $result .= ' ' . $command;

        return $result;
    }

    public function __construct() {
        $this->socketConnector = new SocketConnector;
    }

    /**
     * {@inheritdoc}
     */
    public function start(string $command, string $cwd = null, array $env = [], array $options = []): Promise
    {
        $command = $this->makeCommand($command, $cwd ?? '');

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

        $securityTokens = \random_bytes(SocketConnector::SECURITY_TOKEN_SIZE * 6);
        $written = \fwrite($pipes[0], $securityTokens);

        \fclose($pipes[0]);
        \fclose($pipes[1]);

        if ($written !== SocketConnector::SECURITY_TOKEN_SIZE * 6) {
            \fclose($pipes[2]);
            \proc_close($handle->proc);

            throw new ProcessException("Could not send security tokens to process wrapper");
        }

        $handle->securityTokens = \str_split($securityTokens, SocketConnector::SECURITY_TOKEN_SIZE);
        $handle->wrapperPid = $status['pid'];
        $handle->wrapperStderrPipe = $pipes[2];

        $this->socketConnector->registerPendingProcess($handle);

        return $handle->startDeferred->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function join(ProcessHandle $handle): Promise
    {
        /** @var Handle $handle */

        if ($handle->exitCodeWatcher !== null) {
            Loop::reference($handle->exitCodeWatcher);
        }

        return $handle->endDeferred->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function kill(ProcessHandle $handle)
    {
        /** @var Handle $handle */
        // todo: send a signal to the wrapper?

        // Forcefully kill the process using SIGKILL.
        if (!\proc_terminate($handle->proc)) {
            throw new ProcessException("Terminating process failed");
        }

        Loop::cancel($handle->exitCodeWatcher);
        $handle->exitCodeWatcher = null;

        $handle->status = ProcessStatus::ENDED;

        $handle->endDeferred->fail(new ProcessException("The process was killed"));
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
        /** @var Handle $handle */

        if ($handle->status < ProcessStatus::ENDED) {
            $this->kill($handle);
        }

        if ($handle->exitCodeWatcher !== null) {
            Loop::cancel($handle->exitCodeWatcher);
        }

        for ($i = 0; $i < 4; $i++) {
            if (\is_resource($handle->sockets[$i] ?? null)) {
                \stream_socket_shutdown($handle->sockets[$i], \STREAM_SHUT_RDWR);
                \fclose($handle->sockets[$i]);
            }
        }

        \stream_get_contents($handle->wrapperStderrPipe);
        \fclose($handle->wrapperStderrPipe);

        if (\is_resource($handle->proc)) {
            \proc_close($handle->proc);
        }
    }
}
