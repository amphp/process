<?php

namespace Amp\Process;

use Amp\ByteStream\ClosableStream;
use Amp\ByteStream\InputStream;
use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\ReferencedStream;
use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\StreamException;
use Amp\CancellationToken;
use Amp\Future;
use function Amp\launch;

final class ProcessInputStream implements InputStream, ClosableStream, ReferencedStream
{
    private ?Future $future;

    private bool $pending = false;

    private bool $shouldClose = false;

    private bool $referenced = true;

    private ?ResourceInputStream $resourceStream = null;

    public function __construct(Future $resourceStreamFuture)
    {
        $this->future = launch(function () use ($resourceStreamFuture): ?string {
            try {
                $this->resourceStream = $resourceStreamFuture->await();

                if (!$this->referenced) {
                    $this->resourceStream->unreference();
                }

                if ($this->shouldClose) {
                    $this->resourceStream->close();
                    return null;
                }

                return $this->resourceStream->read();
            } catch (\Throwable $exception) {
                throw new StreamException("Failed to launch process", 0, $exception);
            } finally {
                $this->future = null;
            }
        });
    }

    /**
     * Reads data from the stream.
     *
     * @return string|null
     *
     * @throws PendingReadError Thrown if another read operation is still pending.
     */
    public function read(?CancellationToken $token = null): ?string
    {
        if ($this->pending) {
            throw new PendingReadError;
        }

        if ($this->future) {
            $this->pending = true;
            try {
                return $this->future->await($token);
            } finally {
                $this->pending = false;
            }
        }

        \assert($this->resourceStream);
        return $this->resourceStream->read($token);
    }

    public function reference(): void
    {
        $this->referenced = true;
        $this->resourceStream?->reference();
    }

    public function unreference(): void
    {
        $this->referenced = false;
        $this->resourceStream?->unreference();
    }

    public function close(): void
    {
        $this->shouldClose = true;
        $this->resourceStream?->close();
    }

    public function isClosed(): bool
    {
        return $this->shouldClose;
    }
}
