<?php

namespace Amp\Process;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\WritableStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\ByteStream\StreamException;
use Amp\Future;
use Revolt\EventLoop;

final class WritableProcessStream implements WritableStream
{
    /** @var \SplQueue */
    private \SplQueue $queuedWrites;

    /** @var bool */
    private bool $shouldClose = false;

    private ?WritableResourceStream $resourceStream = null;

    private ?\Throwable $error = null;

    public function __construct(Future $resourceStreamFuture)
    {
        $this->queuedWrites = new \SplQueue;

        EventLoop::queue(function () use ($resourceStreamFuture): void {
            try {
                $resourceStream = $resourceStreamFuture->await();

                while (!$this->queuedWrites->isEmpty()) {
                    /**
                     * @var string $data
                     * @var EventLoop\Suspension $suspension
                     */
                    [$data, $suspension] = $this->queuedWrites->bottom();
                    $resourceStream->write($data);
                    $suspension->resume();
                    $this->queuedWrites->shift();
                }

                $this->resourceStream = $resourceStream;

                if ($this->shouldClose) {
                    $this->resourceStream->close();
                }
            } catch (\Throwable $exception) {
                $this->error = new StreamException("Failed to launch process", 0, $exception);

                while (!$this->queuedWrites->isEmpty()) {
                    [, $suspension] = $this->queuedWrites->shift();
                    $suspension->throw($this->error);
                }
            }
        });
    }

    public function write(string $bytes): void
    {
        if ($this->resourceStream) {
            $this->resourceStream->write($bytes);
            return;
        }

        if ($this->error) {
            throw $this->error;
        }

        if ($this->shouldClose) {
            throw new ClosedException("Stream has already been closed.");
        }

        $suspension = EventLoop::createSuspension();
        $this->queuedWrites->push([$bytes, $suspension]);

        $suspension->suspend();
    }

    public function end(): void
    {
        if ($this->resourceStream) {
            $this->resourceStream->end();
            return;
        }

        if ($this->error) {
            throw $this->error;
        }

        if ($this->shouldClose) {
            throw new ClosedException("Stream has already been closed.");
        }

        $this->shouldClose = true;
    }

    public function close(): void
    {
        $this->shouldClose = true;
        $this->resourceStream?->close();

        if (!$this->queuedWrites->isEmpty()) {
            $error = new ClosedException("Stream closed.");
            do {
                [, $deferredFuture] = $this->queuedWrites->shift();
                $deferredFuture->fail($error);
            } while (!$this->queuedWrites->isEmpty());
        }
    }

    public function isClosed(): bool
    {
        return $this->shouldClose;
    }

	public function isWritable(): bool {
		return $this->resourceStream?->isWritable() ?? false;
	}
}
