<?php

namespace Amp\Process;

use Amp\ByteStream\ClosableStream;
use Amp\ByteStream\ClosedException;
use Amp\ByteStream\OutputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\ByteStream\StreamException;
use Amp\Deferred;
use Amp\Future;
use Revolt\EventLoop;

final class ProcessOutputStream implements OutputStream, ClosableStream
{
    /** @var \SplQueue */
    private \SplQueue $queuedWrites;

    /** @var bool */
    private bool $shouldClose = false;

    private ?ResourceOutputStream $resourceStream = null;

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
                     * @var \Amp\Deferred $deferred
                     */
                    [$data, $deferred] = $this->queuedWrites->shift();
                    $resourceStream->write($data);
                    $deferred->complete();
                }

                $this->resourceStream = $resourceStream;

                if ($this->shouldClose) {
                    $this->resourceStream->close();
                }
            } catch (\Throwable $exception) {
                $this->error = new StreamException("Failed to launch process", 0, $exception);

                while (!$this->queuedWrites->isEmpty()) {
                    [, $deferred] = $this->queuedWrites->shift();
                    $deferred->error($this->error);
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

        $deferred = new Deferred;
        $this->queuedWrites->push([$data, $deferred]);

        return $deferred->getFuture();
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

        $deferred = new Deferred;
        $this->queuedWrites->push([$finalData, $deferred]);

        $this->shouldClose = true;

        return $deferred->getFuture();
    }

    public function close(): void
    {
        $this->shouldClose = true;
        $this->resourceStream?->close();

        if (!$this->queuedWrites->isEmpty()) {
            $error = new ClosedException("Stream closed.");
            do {
                [, $deferred] = $this->queuedWrites->shift();
                $deferred->fail($error);
            } while (!$this->queuedWrites->isEmpty());
        }
    }

    public function isClosed(): bool
    {
        return $this->shouldClose;
    }

    public function isWritable(): bool
    {
        return !$this->isClosed();
    }
}
