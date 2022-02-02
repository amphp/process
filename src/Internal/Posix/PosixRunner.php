<?php

namespace Amp\Process\Internal\Posix;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\ProcessException;
use Revolt\EventLoop;

/** @internal */
final class PosixRunner implements ProcessRunner
{
    private const FD_SPEC = [
        ["pipe", "r"], // stdin
        ["pipe", "w"], // stdout
        ["pipe", "w"], // stderr
        ["pipe", "w"], // exit code pipe
    ];

    private const NULL_DESCRIPTOR = ["file", "/dev/null", "r"];

    /** @var string|null */
    private static ?string $fdPath = null;

    public function start(
        string $command,
        string $workingDirectory = null,
        array $environment = [],
        array $options = []
    ): ProcessHandle {
        if (!\extension_loaded('pcntl')) {
            throw new ProcessException('Missing ext-pcntl to run processes with PosixRunner');
        }

        if (!\extension_loaded('posix')) {
            throw new ProcessException('Missing ext-posix to run processes with PosixRunner');
        }

        $command = \sprintf(
            '{ (%s) <&3 3<&- 3>/dev/null & } 3<&0; trap "" INT TERM QUIT HUP;' .
            'pid=$!; echo $pid >&3; wait $pid; RC=$?; echo $RC >&3; exit $RC',
            $command
        );

        $proc = @\proc_open(
            $command,
            $this->generateFds(),
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

        $handle = new PosixHandle($proc);

        $extraDataPipe = $pipes[3];
        \stream_set_blocking($extraDataPipe, false);

        $suspension = EventLoop::getSuspension();
        EventLoop::onReadable($extraDataPipe, static function (string $callbackId) use ($suspension): void {
            EventLoop::cancel($callbackId);

            $suspension->resume();
        });

        $suspension->suspend();

        $pid = \rtrim(@\fgets($extraDataPipe));
        if (!$pid || !\is_numeric($pid)) {
            throw new ProcessException("Could not determine PID");
        }

        $handle->status = ProcessStatus::RUNNING;
        $handle->pid = (int) $pid;

        $handle->stdin = new WritableResourceStream($pipes[0]);
        $handle->stdout = new ReadableResourceStream($pipes[1]);
        $handle->stderr = new ReadableResourceStream($pipes[2]);

        $handle->extraDataPipeCallbackId = EventLoop::onReadable(
            $extraDataPipe,
            static function (string $callbackId, $stream) use ($handle, $extraDataPipe) {
                $handle->extraDataPipeCallbackId = null;
                EventLoop::cancel($callbackId);

                $handle->status = ProcessStatus::ENDED;

                if (!\is_resource($stream) || \feof($stream)) {
                    $handle->joinDeferred->error(new ProcessException("Process ended unexpectedly"));
                } else {
                    $handle->joinDeferred->complete((int) \rtrim(@\stream_get_contents($stream)));
                }

                // Don't call proc_close here or close output streams, as there might still be stream reads
                $handle->stdin->close();

                \fclose($extraDataPipe);
            }
        );

        EventLoop::unreference($handle->extraDataPipeCallbackId);

        return $handle;
    }

    private function generateFds(): array
    {
        if (self::$fdPath === null) {
            self::$fdPath = \file_exists("/dev/fd") ? "/dev/fd" : "/proc/self/fd";
        }

        $fdList = @\scandir(self::$fdPath, \SCANDIR_SORT_NONE);

        if ($fdList === false) {
            throw new ProcessException("Unable to list open file descriptors");
        }

        $fdList = \array_filter($fdList, static function (string $path): bool {
            return $path !== "." && $path !== "..";
        });

        $fds = [];
        foreach ($fdList as $id) {
            $fds[(int) $id] = self::NULL_DESCRIPTOR;
        }

        return self::FD_SPEC + $fds;
    }

    public function join(ProcessHandle $handle): int
    {
        /** @var PosixHandle $handle */
        if ($handle->extraDataPipeCallbackId !== null) {
            EventLoop::reference($handle->extraDataPipeCallbackId);
        }

        return $handle->joinDeferred->getFuture()->await();
    }

    public function kill(ProcessHandle $handle): void
    {
        $this->signal($handle, 9);
    }

    public function signal(ProcessHandle $handle, int $signal): void
    {
        /** @noinspection PhpComposerExtensionStubsInspection */
        @\posix_kill($handle->pid, $signal);
    }

    public function destroy(ProcessHandle $handle): void
    {
        /** @var PosixHandle $handle */
        if ($handle->status < ProcessStatus::ENDED && \getmypid() === $handle->originalParentPid) {
            try {
                $this->kill($handle);
            } catch (ProcessException) {
                // ignore
            }
        }
    }
}
