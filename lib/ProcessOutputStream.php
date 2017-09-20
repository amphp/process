<?php

namespace Amp\Process;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\OutputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\ByteStream\StreamException;
use Amp\Deferred;
use Amp\Failure;
use Amp\Promise;

class ProcessOutputStream implements OutputStream {
    /** @var array */
    private $queuedWrites = [];

    /** @var bool */
    private $shouldClose = false;

    /** @var ResourceOutputStream */
    private $resourceStream;

    /** @var StreamException|null */
    private $error;

    public function __construct(Promise $resourceStreamPromise) {
        $resourceStreamPromise->onResolve(function ($error, $resourceStream) {
            if ($error) {
                $this->error = new StreamException("Failed to launch process", 0, $error);

                while ($write = \array_shift($this->queuedWrites)) {
                    /** @var $deferred Deferred */
                    list(, $deferred) = $write;
                    $deferred->fail($this->error);
                }

                return;
            }

            $this->resourceStream = $resourceStream;

            $queue = $this->queuedWrites;
            $this->queuedWrites = [];

            foreach ($queue as list($data, $deferred)) {
                $deferred->resolve($this->resourceStream->write($data));
            }

            if ($this->shouldClose) {
                $this->resourceStream->close();
            }
        });
    }

    /** @inheritdoc */
    public function write(string $data): Promise {
        if ($this->resourceStream) {
            return $this->resourceStream->write($data);
        }

        if ($this->error) {
            return new Failure($this->error);
        }

        if ($this->shouldClose) {
            throw new ClosedException("Stream has already been closed.");
        }

        $deferred = new Deferred;
        $this->queuedWrites[] = [$data, $deferred];

        return $deferred->promise();
    }

    /** @inheritdoc */
    public function end(string $finalData = ""): Promise {
        if ($this->resourceStream) {
            return $this->resourceStream->end($finalData);
        }

        if ($this->error) {
            return new Failure($this->error);
        }

        if ($this->shouldClose) {
            throw new ClosedException("Stream has already been closed.");
        }

        $deferred = new Deferred;
        $this->queuedWrites[] = [$finalData, $deferred];

        $this->shouldClose = true;

        return $deferred->promise();
    }

    public function close() {
        $this->shouldClose = true;

        if ($this->resourceStream) {
            $this->resourceStream->close();
        }
    }
}
