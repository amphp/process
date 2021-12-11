<?php

namespace Amp\Process\Internal\Posix;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\ProcessException;
use Revolt\EventLoop;

final class Runner implements ProcessRunner
{
    private const FD_SPEC = [
        ["pipe", "r"], // stdin
        ["pipe", "w"], // stdout
        ["pipe", "w"], // stderr
        ["pipe", "w"], // exit code pipe
    ];

    /** @var string|null */
    private static ?string $fdPath = null;

    public function start(
        string $command,
        string $workingDirectory = null,
        array $environment = [],
        array $options = []
    ): ProcessHandle {
        $command = \sprintf(
            '{ (%s) <&3 3<&- 3>/dev/null & } 3<&0; trap "" INT TERM QUIT HUP;' .
            'pid=$!; echo $pid >&3; wait $pid; RC=$?; echo $RC >&3; exit $RC',
            $command
        );

        $handle = new Handle;
        $handle->proc = @\proc_open(
            $command,
            $this->generateFds(),
            $pipes,
            $workingDirectory ?: null,
            $environment ?: null,
            $options
        );

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

        $handle->extraDataPipe = $pipes[3];
        \stream_set_blocking($handle->extraDataPipe, false);

        $suspension = EventLoop::createSuspension();
        $handle->extraDataPipeStartWatcher = EventLoop::onReadable(
            $handle->extraDataPipe,
            static function (string $callbackId) use ($suspension): void {
                EventLoop::cancel($callbackId);

                $suspension->resume();
            }
        );

        $suspension->suspend();

        $pid = \rtrim(@\fgets($pipes[3]));
        if (!$pid || !\is_numeric($pid)) {
            throw new ProcessException("Could not determine PID");
        }

        $handle->status = ProcessStatus::RUNNING;
        $handle->pid = (int) $pid;

        $handle->stdin = new WritableResourceStream($pipes[0]);
        $handle->stdout = new ReadableResourceStream($pipes[1]);
        $handle->stderr = new ReadableResourceStream($pipes[2]);

        $handle->extraDataPipeWatcher = EventLoop::onReadable(
            $handle->extraDataPipe,
            static function (string $callbackId, $stream) use ($handle) {
                EventLoop::cancel($callbackId);

                $handle->extraDataPipeWatcher = null;
                $handle->status = ProcessStatus::ENDED;

                if (!\is_resource($stream) || \feof($stream)) {
                    $handle->joinDeferred->error(new ProcessException("Process ended unexpectedly"));
                } else {
                    $handle->joinDeferred->complete((int) \rtrim(@\stream_get_contents($stream)));
                }

                if ($handle->extraDataPipeWatcher !== null) {
                    EventLoop::cancel($handle->extraDataPipeWatcher);
                    $handle->extraDataPipeWatcher = null;
                }

                if ($handle->extraDataPipeStartWatcher !== null) {
                    EventLoop::cancel($handle->extraDataPipeStartWatcher);
                    $handle->extraDataPipeStartWatcher = null;
                }

                if (\is_resource($handle->extraDataPipe)) {
                    \fclose($handle->extraDataPipe);
                }

                // Don't call proc_close here or close output streams, as there might still be stream reads
                $handle->stdin->close();
            }
        );

        EventLoop::unreference($handle->extraDataPipeWatcher);

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
            $fds[(int) $id] = ["file", "/dev/null", "r"];
        }

        return self::FD_SPEC + $fds;
    }

    public function join(ProcessHandle $handle): int
    {
        /** @var Handle $handle */
        if ($handle->extraDataPipeWatcher !== null) {
            EventLoop::reference($handle->extraDataPipeWatcher);
        }

        return $handle->joinDeferred->getFuture()->await();
    }

    public function kill(ProcessHandle $handle): void
    {
        $this->signal($handle, 9);
    }

    public function signal(ProcessHandle $handle, int $signo): void
    {
        /** @noinspection PhpComposerExtensionStubsInspection */
        @\posix_kill($handle->pid, $signo);
    }

    public function destroy(ProcessHandle $handle): void
    {
        /** @var Handle $handle */
        if ($handle->status < ProcessStatus::ENDED && \getmypid() === $handle->originalParentPid) {
            try {
                $this->kill($handle);
            } catch (ProcessException) {
                // ignore
            }
        }
    }
}
