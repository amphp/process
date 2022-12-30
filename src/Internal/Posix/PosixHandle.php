<?php declare(strict_types=1);

namespace Amp\Process\Internal\Posix;

use Amp\ByteStream\WritableResourceStream;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\ProcessException;
use Revolt\EventLoop;

/** @internal */
final class PosixHandle extends ProcessHandle
{
    private ?string $extraDataPipeCallbackId;

    private readonly int $shellPid;

    /**
     * @param resource $proc Resource from proc_open()
     * @param resource $extraDataPipe Stream resource for exit code
     */
    public function __construct(
        $proc,
        int $pid,
        WritableResourceStream $stdin,
        $extraDataPipe,
    ) {
        parent::__construct($proc);

        $this->status = ProcessStatus::Running;
        $this->pid = $pid;
        $this->shellPid = $shellPid = \proc_get_status($proc)['pid'];

        $status = &$this->status;
        $deferred = $this->joinDeferred;
        $stdin = \WeakReference::create($stdin);
        $this->extraDataPipeCallbackId = EventLoop::unreference(EventLoop::onReadable(
            $extraDataPipe,
            static function (string $callbackId, $stream) use (&$status, $deferred, $stdin, $shellPid): void {
                EventLoop::disable($callbackId);

                $status = ProcessStatus::Ended;

                if (!\is_resource($stream) || \feof($stream)) {
                    $deferred->error(new ProcessException("Process ended unexpectedly"));
                } else {
                    $deferred->complete((int) \rtrim(\stream_get_contents($stream)));
                }

                // Don't call proc_close here or close output streams, as there might still be stream reads
                $stdin->get()?->close();

                \fclose($stream);

                self::waitPid($shellPid);
            },
        ));
    }

    public function reference(): void
    {
        if ($this->extraDataPipeCallbackId !== null) {
            EventLoop::reference($this->extraDataPipeCallbackId);
        }
    }

    public function unreference(): void
    {
        if ($this->extraDataPipeCallbackId !== null) {
            EventLoop::unreference($this->extraDataPipeCallbackId);
        }
    }

    private static function waitPid(int $pid): void
    {
        if (self::hasChildExited($pid)) {
            return;
        }

        EventLoop::unreference(EventLoop::defer(static fn () => self::waitPid($pid)));
    }

    private static function hasChildExited(int $pid): bool
    {
        return !\extension_loaded('pcntl') || \pcntl_waitpid($pid, $status, \WNOHANG) !== 0;
    }

    public function __destruct()
    {
        if ($this->extraDataPipeCallbackId !== null) {
            EventLoop::cancel($this->extraDataPipeCallbackId);
            $this->extraDataPipeCallbackId = null;
        }

        if ($this->joinDeferred->isComplete()) {
            return;
        }

        self::waitPid($this->shellPid);
    }
}
