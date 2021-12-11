<?php

namespace Amp\Process;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\ResourceStream;
use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\Future;
use function Amp\async;

final class ReadableProcessStream implements ReadableStream, ResourceStream
{
    private ?Future $future;

    private bool $pending = false;

    private bool $shouldClose = false;

    private bool $referenced = true;

    private ?ReadableResourceStream $resourceStream = null;

    public function __construct(Future $resourceStreamFuture)
    {
        $this->future = async(function () use ($resourceStreamFuture): ?ReadableResourceStream {
            try {
                $this->resourceStream = $resourceStreamFuture->await();

                if (!$this->referenced) {
                    $this->resourceStream->unreference();
                }

                if ($this->shouldClose) {
                    $this->resourceStream->close();
                    return null;
                }

                return $this->resourceStream;
            } catch (\Throwable $exception) {
                throw new StreamException("Failed to async process", 0, $exception);
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
    public function read(?Cancellation $cancellation = null, ?int $length = null): ?string
    {
        if ($this->pending) {
            throw new PendingReadError;
        }

        if ($this->future) {
            $this->pending = true;
            try {
                $this->future->await($cancellation);
            } finally {
                $this->pending = false;
            }
        }

        \assert($this->resourceStream);
        return $this->resourceStream->read($cancellation, $length);
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

	public function isReadable(): bool {
		return $this->resourceStream?->isReadable() ?? false;
	}

    public function getResource()
    {
        if ($this->future) {
            $this->future->await();
        }

        return $this->resourceStream?->getResource();
    }
}
