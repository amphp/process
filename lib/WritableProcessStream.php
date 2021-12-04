<?php

namespace Amp\Process;

use Amp\ByteStream\ClosableStream;
use Amp\ByteStream\ClosedException;
use Amp\ByteStream\WritableStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\ByteStream\StreamException;
use Amp\DeferredFuture;
use Amp\Future;
use Revolt\EventLoop;

final class WritableProcessStream implements WritableStream, ClosableStream
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
                     * @var string        $data
                     * @var \Amp\DeferredFuture $DeferredFuture
                     */
                    [$data, $DeferredFuture] = $this->queuedWrites->shift();
                    $resourceStream->write($data);
                    $DeferredFuture->complete();
                }

                $this->resourceStream = $resourceStream;

                if ($this->shouldClose) {
                    $this->resourceStream->close();
                }
            } catch (\Throwable $exception) {
                $this->error = new StreamException("Failed to launch process", 0, $exception);

                while (!$this->queuedWrites->isEmpty()) {
                    [, $DeferredFuture] = $this->queuedWrites->shift();
                    $DeferredFuture->error($this->error);
                }
            }
        });
    }

    /** @inheritdoc */
    public function write(string $data): Future
    {
        if ($this->resourceStream) {
            return $this->resourceStream->write($data);
        }

        if ($this->error) {
            return Future::error($this->error);
        }

        if ($this->shouldClose) {
            throw new ClosedException("Stream has already been closed.");
        }

        $DeferredFuture = new DeferredFuture;
        $this->queuedWrites->push([$data, $DeferredFuture]);

        return $DeferredFuture->getFuture();
    }

    /** @inheritdoc */
    public function end(string $finalData = ""): Future
    {
        if ($this->resourceStream) {
            return $this->resourceStream->end($finalData);
        }

        if ($this->error) {
            return Future::error($this->error);
        }

        if ($this->shouldClose) {
            throw new ClosedException("Stream has already been closed.");
        }

        $DeferredFuture = new DeferredFuture;
        $this->queuedWrites->push([$finalData, $DeferredFuture]);

        $this->shouldClose = true;

        return $DeferredFuture->getFuture();
    }

    public function close(): void
    {
        $this->shouldClose = true;
        $this->resourceStream?->close();

        if (!$this->queuedWrites->isEmpty()) {
            $error = new ClosedException("Stream closed.");
            do {
                [, $DeferredFuture] = $this->queuedWrites->shift();
                $DeferredFuture->fail($error);
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
