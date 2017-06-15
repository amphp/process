<?php

namespace Amp\Process;

use Amp\ByteStream\InputStream;
use Amp\ByteStream\OutputStream;
use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Promise;
use function Amp\call;

class StreamedProcess implements InputStream, OutputStream {
    const CHUNK_SIZE = 8192;

    /** @var \Amp\Process\Process */
    private $process;

    /** @var \Amp\ByteStream\ResourceOutputStream */
    private $stdin;

    /** @var \Amp\ByteStream\ResourceInputStream */
    private $stdout;

    /** @var \Amp\ByteStream\ResourceInputStream */
    private $stderr;

    /**
     * @param   string|array $command Command to run.
     * @param   string|null $cwd Working directory or use an empty string to use the working directory of the current
     *     PHP process.
     * @param   mixed[] $env Environment variables or use an empty array to inherit from the current PHP process.
     * @param   mixed[] $options Options for proc_open().
     */
    public function __construct($command, string $cwd = null, array $env = [], array $options = []) {
        $this->process = new Process($command, $cwd, $env, $options);
    }

    /**
     * Resets process values.
     */
    public function __clone() {
        $this->process = clone $this->process;
        $this->stdin = null;
        $this->stdout = null;
        $this->stderr = null;
    }

    /**
     * {@inheritdoc}
     */
    public function start() {
        $this->process->start();

        $this->stdin = new ResourceOutputStream($this->process->getStdin());
        $this->stdout = new ResourceInputStream($this->process->getStdout());
        $this->stderr = new ResourceInputStream($this->process->getStderr());
    }

    /** @inheritdoc */
    public function join(): Promise {
        return call(function () {
            try {
                return yield $this->process->join();
            } finally {
                $this->stdin->close();
                $this->stdout->close();
                $this->stderr->close();
            }
        });
    }

    /** @inheritdoc */
    public function isRunning(): bool {
        return $this->process->isRunning();
    }

    /** @inheritdoc */
    public function write(string $data): Promise {
        if (!$this->stdin) {
            throw new StatusError("The process has not been started");
        }

        return $this->stdin->write($data);
    }

    /** @inheritdoc */
    public function end(string $data = ""): Promise {
        if (!$this->stdin) {
            throw new StatusError("The process has not been started");
        }

        return $this->stdin->end($data);
    }

    /** @inheritdoc */
    public function read(): Promise {
        if (!$this->stdout) {
            throw new StatusError("The process has not been started");
        }

        return $this->stdout->read();
    }

    /**
     * Reads from stderr in the same way that read() reads from stdout.
     *
     * @return \Amp\Promise
     */
    public function readError(): Promise {
        if (!$this->stderr) {
            throw new StatusError("The process has not been started");
        }

        return $this->stderr->read();
    }

    /** @inheritdoc */
    public function kill() {
        $this->process->kill();
    }

    /** @inheritdoc */
    public function getPid(): int {
        return $this->process->getPid();
    }

    /** @inheritdoc */
    public function signal(int $signo) {
        $this->process->signal($signo);
    }
}
