<?php

namespace Amp\Process\Internal\Posix;

use Amp\ByteStream\WritableResourceStream;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\ProcessException;
use Revolt\EventLoop;

/** @internal */
final class PosixHandle extends ProcessHandle
{
    private readonly string $extraDataPipeCallbackId;

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

        $this->status = ProcessStatus::RUNNING;
        $this->pid = $pid;
        $this->shellPid = \proc_get_status($proc)['pid'];

        $status = &$this->status;
        $deferred = $this->joinDeferred;
        $stdin = \WeakReference::create($stdin);
        $this->extraDataPipeCallbackId = EventLoop::unreference(EventLoop::onReadable(
            $extraDataPipe,
            static function (string $callbackId, $stream) use (&$status, $deferred, $stdin): void {
                EventLoop::disable($callbackId);

                $status = ProcessStatus::ENDED;

                if (!\is_resource($stream) || \feof($stream)) {
                    $deferred->error(new ProcessException("Process ended unexpectedly"));
                } else {
                    $deferred->complete((int) \rtrim(\stream_get_contents($stream)));
                }

                // Don't call proc_close here or close output streams, as there might still be stream reads
                $stdin->get()?->close();

                \fclose($stream);
            },
        ));
    }

    public function reference(): void
    {
        EventLoop::reference($this->extraDataPipeCallbackId);
    }

    public function __destruct()
    {
        EventLoop::cancel($this->extraDataPipeCallbackId);

        $pid = $this->shellPid;
        EventLoop::unreference(EventLoop::repeat(0.1, static function (string $callbackId) use ($pid): void {
            if (!\extension_loaded('pcntl') || \pcntl_waitpid($pid, $status, \WNOHANG) !== 0) {
                EventLoop::cancel($callbackId);
            }
        }));
    }
}
