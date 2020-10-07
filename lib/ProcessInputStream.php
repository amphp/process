<?php

namespace Amp\Process;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\StreamException;
use Amp\Deferred;
use Amp\Promise;
use Amp\Success;
use function Amp\await;
use function Amp\defer;

final class ProcessInputStream implements InputStream
{
    private ?Deferred $initialRead = null;

    private bool $shouldClose = false;

    private bool $referenced = true;

    private ResourceInputStream $resourceStream;

    private ?\Throwable $error = null;

    public function __construct(Promise $resourceStreamPromise)
    {
        defer(function () use ($resourceStreamPromise): void {
            try {
                $this->resourceStream = await($resourceStreamPromise);

                if (!$this->referenced) {
                    $this->resourceStream->unreference();
                }

                if ($this->shouldClose) {
                    $this->resourceStream->close();
                }

                if ($this->initialRead) {
                    $initialRead = $this->initialRead;
                    $this->initialRead = null;
                    $initialRead->resolve($this->shouldClose ? null : $this->resourceStream->read());
                }
            } catch (\Throwable $exception) {
                $this->error = new StreamException("Failed to launch process", 0, $exception);
                if ($this->initialRead) {
                    $initialRead = $this->initialRead;
                    $this->initialRead = null;
                    $initialRead->fail($this->error);
                }
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
    public function read(): ?string
    {
        if ($this->initialRead) {
            throw new PendingReadError;
        }

        if ($this->error) {
            throw $this->error;
        }

        if (isset($this->resourceStream)) {
            return $this->resourceStream->read();
        }

        if ($this->shouldClose) {
            return new Success; // Resolve reads on closed streams with null.
        }

        $this->initialRead = new Deferred;

        return await($this->initialRead->promise());
    }

    public function reference(): void
    {
        $this->referenced = true;

        if ($this->resourceStream) {
            $this->resourceStream->reference();
        }
    }

    public function unreference(): void
    {
        $this->referenced = false;

        if ($this->resourceStream) {
            $this->resourceStream->unreference();
        }
    }

    public function close(): void
    {
        $this->shouldClose = true;

        if ($this->initialRead) {
            $initialRead = $this->initialRead;
            $this->initialRead = null;
            $initialRead->resolve();
        }

        if (isset($this->resourceStream)) {
            $this->resourceStream->close();
        }
    }
}
