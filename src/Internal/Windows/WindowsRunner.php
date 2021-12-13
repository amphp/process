<?php

namespace Amp\Process\Internal\Windows;

use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\ProcessException;
use const Amp\Process\BIN_DIR;

/**
 * @internal
 * @codeCoverageIgnore Windows only.
 */
final class WindowsRunner implements ProcessRunner
{
    private const FD_SPEC = [
        ["pipe", "r"], // stdin
        ["pipe", "w"], // stdout
        ["pipe", "w"], // stderr
        ["pipe", "w"], // exit code pipe
    ];

    private const WRAPPER_EXE_PATH = PHP_INT_SIZE === 8
        ? BIN_DIR . '\\windows\\ProcessWrapper64.exe'
        : BIN_DIR . '\\windows\\ProcessWrapper.exe';

    private static ?string $pharWrapperPath = null;

    private SocketConnector $socketConnector;

    public function __construct()
    {
        $this->socketConnector = new SocketConnector;
    }

    public function start(
        string $command,
        string $workingDirectory = null,
        array $environment = [],
        array $options = []
    ): ProcessHandle {
        if (\str_contains($command, "\0")) {
            throw new ProcessException("Can't execute commands that contain NUL bytes.");
        }

        $options['bypass_shell'] = true;

        $proc = @\proc_open(
            $this->makeCommand($workingDirectory ?? ''),
            self::FD_SPEC,
            $pipes,
            $workingDirectory ?: null,
            $environment ?: null,
            $options
        );

        if (!\is_resource($proc)) {
            $message = "Could not start process";
            if ($error = \error_get_last()) {
                $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
            }
            throw new ProcessException($message);
        }

        $status = \proc_get_status($proc);
        $handle = new WindowsHandle($proc);

        $securityTokens = \random_bytes(SocketConnector::SECURITY_TOKEN_SIZE * 6);
        $written = \fwrite($pipes[0], $securityTokens . "\0" . $command . "\0");

        \fclose($pipes[0]);
        \fclose($pipes[1]);

        if ($written !== SocketConnector::SECURITY_TOKEN_SIZE * 6 + \strlen($command) + 2) {
            \fclose($pipes[2]);
            \proc_close($proc);

            throw new ProcessException("Could not send security tokens / command to process wrapper");
        }

        $handle->securityTokens = \str_split($securityTokens, SocketConnector::SECURITY_TOKEN_SIZE);
        $handle->wrapperPid = $status['pid'];

        try {
            $this->socketConnector->connectPipes($handle);
        } catch (\Exception) {
            $running = \is_resource($proc) && \proc_get_status($proc)['running'];

            $message = null;
            if (!$running) {
                $message = \stream_get_contents($pipes[2]);
            }

            \fclose($pipes[2]);
            \proc_close($proc);

            throw new ProcessException(\trim($message ?: 'Process did not connect to server before timeout elapsed'));
        }

        return $handle;
    }

    public function join(ProcessHandle $handle): int
    {
        /** @var WindowsHandle $handle */
        $handle->exitCodeStream->reference();

        return $handle->joinDeferred->getFuture()->await();
    }

    public function kill(ProcessHandle $handle): void
    {
        /** @var WindowsHandle $handle */
        \exec('taskkill /F /T /PID ' . $handle->pid . ' 2>&1', $output, $exitCode);
        if ($exitCode) {
            $message = \implode(\PHP_EOL, $output);
            if ($exitCode === 128) { // process no longer running
                return;
            }

            throw new ProcessException("Terminating process failed: " . $exitCode . ': ' . $message);
        }
    }

    public function signal(ProcessHandle $handle, int $signal): void
    {
        throw new ProcessException('Signals are not supported on Windows');
    }

    public function destroy(ProcessHandle $handle): void
    {
        /** @var WindowsHandle $handle */
        if ($handle->status < ProcessStatus::ENDED && \getmypid() === $handle->originalParentPid) {
            try {
                $this->kill($handle);
            } catch (ProcessException) {
                // ignore
            }
        }
    }

    private function makeCommand(string $workingDirectory): string
    {
        $wrapperPath = self::WRAPPER_EXE_PATH;

        // We can't execute the exe from within the PHAR, so copy it out...
        if (\strncmp($wrapperPath, "phar://", 7) === 0) {
            if (self::$pharWrapperPath === null) {
                $fileHash = \hash('sha1', \file_get_contents(self::WRAPPER_EXE_PATH));
                self::$pharWrapperPath = \sys_get_temp_dir() . "amphp-process-wrapper-" . $fileHash;

                if (
                    !\file_exists(self::$pharWrapperPath)
                    || \hash('sha1', \file_get_contents(self::$pharWrapperPath)) !== $fileHash
                ) {
                    \copy(self::WRAPPER_EXE_PATH, self::$pharWrapperPath);
                }
            }

            $wrapperPath = self::$pharWrapperPath;
        }

        $result = \sprintf(
            '%s --address=%s --port=%d --token-size=%d',
            \escapeshellarg($wrapperPath),
            $this->socketConnector->address,
            $this->socketConnector->port,
            SocketConnector::SECURITY_TOKEN_SIZE
        );

        if ($workingDirectory !== '') {
            $result .= ' ' . \escapeshellarg('--cwd=' . \rtrim($workingDirectory, '\\'));
        }

        return $result;
    }
}
