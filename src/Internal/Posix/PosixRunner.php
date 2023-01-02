<?php declare(strict_types=1);

namespace Amp\Process\Internal\Posix;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Process\Internal\ProcessContext;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\Internal\ProcessStreams;
use Amp\Process\ProcessException;
use Revolt\EventLoop;

/**
 * @internal
 * @implements ProcessRunner<PosixHandle>
 */
final class PosixRunner implements ProcessRunner
{
    use ForbidCloning;
    use ForbidSerialization;

    private const FD_SPEC = [
        ["pipe", "r"], // stdin
        ["pipe", "w"], // stdout
        ["pipe", "w"], // stderr
        ["pipe", "w"], // exit code pipe
    ];
    private const NULL_DESCRIPTOR = ["file", "/dev/null", "r"];

    private static ?string $fdPath = null;

    public function start(
        string $command,
        Cancellation $cancellation,
        string $workingDirectory = null,
        array $environment = [],
        array $options = [],
    ): ProcessContext {
        if (!\extension_loaded('posix')) {
            throw new ProcessException('Missing ext-posix to run processes with PosixRunner');
        }

        $command = \sprintf(
            '{ (%s) <&3 3<&- 3>/dev/null & } 3<&0; trap "" INT TERM QUIT HUP;' .
            'pid=$!; echo $pid >&3; wait $pid; RC=$?; echo $RC >&3; exit $RC',
            $command
        );

        // Errors checked below, so suppressing all errors during call to proc_open() and $this->generateFds().
        \set_error_handler(static fn () => true);

        try {
            $proc = \proc_open(
                $command,
                $this->generateFds(),
                $pipes,
                $workingDirectory,
                $environment ?: null,
                $options,
            );
        } finally {
            \restore_error_handler();
        }

        if (!\is_resource($proc)) {
            $message = "Could not start process";
            if ($error = \error_get_last()) {
                $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
            }
            throw new ProcessException($message);
        }

        $extraDataPipe = $pipes[3];
        \stream_set_blocking($extraDataPipe, false);

        $suspension = EventLoop::getSuspension();

        $callbackId = EventLoop::onReadable(
            $extraDataPipe,
            static function (string $callbackId) use ($suspension): void {
                EventLoop::cancel($callbackId);
                $suspension->resume();
            },
        );

        $cancellationId = $cancellation->subscribe(
            static function (CancelledException $e) use ($suspension, $callbackId): void {
                EventLoop::cancel($callbackId);
                $suspension->throw($e);
            },
        );

        try {
            $suspension->suspend();
        } catch (\Throwable $exception) {
            \proc_terminate($proc);
            \proc_close($proc);
            throw $exception;
        } finally {
            $cancellation->unsubscribe($cancellationId);
        }

        $pid = \rtrim(\fgets($extraDataPipe));
        if (!$pid || !\is_numeric($pid)) {
            throw new ProcessException("Could not determine PID");
        }

        $stdin = new WritableResourceStream($pipes[0]);
        $stdout = new ReadableResourceStream($pipes[1]);
        $stderr = new ReadableResourceStream($pipes[2]);

        return new ProcessContext(
            new PosixHandle($proc, (int) $pid, $stdin, $extraDataPipe),
            new ProcessStreams($stdin, $stdout, $stderr),
        );
    }

    private function generateFds(): array
    {
        if (self::$fdPath === null) {
            self::$fdPath = \file_exists("/dev/fd") ? "/dev/fd" : "/proc/self/fd";
        }

        $fdList = \scandir(self::$fdPath, \SCANDIR_SORT_NONE);

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

    public function join(ProcessHandle $handle, ?Cancellation $cancellation = null): int
    {
        /** @var PosixHandle $handle */
        $handle->reference();

        try {
            return $handle->joinDeferred->getFuture()->await($cancellation);
        } finally {
            $handle->unreference();
        }
    }

    public function kill(ProcessHandle $handle): void
    {
        /** @var PosixHandle $handle */
        $handle->reference();

        $this->signal($handle, 9);
    }

    public function signal(ProcessHandle $handle, int $signal): void
    {
        /** @noinspection PhpComposerExtensionStubsInspection */
        \posix_kill($handle->pid, $signal);
    }

    public function destroy(ProcessHandle $handle): void
    {
        /** @var PosixHandle $handle */
        if ($handle->status !== ProcessStatus::Ended && \getmypid() === $handle->originalParentPid) {
            try {
                $this->kill($handle);
            } catch (ProcessException) {
                // ignore
            }
        }
    }
}
