<?php

namespace Amp\Process;

use Amp\{ Deferred, Emitter, Failure, Loop, Message, Promise, Success };

class StreamedProcess {
    const CHUNK_SIZE = 8192;

    /** @var \Amp\Process\Process */
    private $process;

    /** @var \Amp\Emitter Emits bytes read from STDOUT. */
    private $stdoutEmitter;

    /** @var \Amp\Emitter Emits bytes read from STDERR. */
    private $stderrEmitter;

    /** @var \Amp\Message */
    private $stdoutMessage;

    /** @var \Amp\Message */
    private $stderrMessage;

    /** @var string|null */
    private $stdinWatcher;

    /** @var string|null */
    private $stdoutWatcher;

    /** @var string|null */
    private $stderrWatcher;

    /** @var \SplQueue Queue of data to write to STDIN. */
    private $writeQueue;

    /**
     * @param   string|array $command Command to run.
     * @param   string|null $cwd Working directory or use an empty string to use the working directory of the current
     *     PHP process.
     * @param   mixed[] $env Environment variables or use an empty array to inherit from the current PHP process.
     * @param   mixed[] $options Options for proc_open().
     */
    public function __construct($command, string $cwd = null, array $env = [], array $options = []) {
        $this->process = new Process($command, $cwd, $env, $options);
        $this->stdoutEmitter = new Emitter;
        $this->stderrEmitter = new Emitter;
        $this->stdoutMessage = new Message($this->stdoutEmitter->stream());
        $this->stderrMessage = new Message($this->stderrEmitter->stream());
        $this->writeQueue = new \SplQueue;
    }

    /**
     * Resets process values.
     */
    public function __clone() {
        $this->process = clone $this->process;
        $this->stdinWatcher = null;
        $this->stdoutWatcher = null;
        $this->stderrWatcher = null;
        $this->stdoutEmitter = new Emitter;
        $this->stderrEmitter = new Emitter;
        $this->stdoutMessage = new Message($this->stdoutEmitter->stream());
        $this->stderrMessage = new Message($this->stderrEmitter->stream());
        $this->writeQueue = new \SplQueue;
    }

    /**
     * {@inheritdoc}
     */
    public function start() {
        $this->process->start();

        $process = $this->process;
        $writes = $this->writeQueue;
        $this->stdinWatcher = Loop::onWritable($this->process->getStdin(), static function ($watcher, $resource) use (
            $process, $writes
        ) {
            try {
                while (!$writes->isEmpty()) {
                    /** @var \Amp\Deferred $deferred */
                    list($data, $previous, $deferred) = $writes->shift();
                    $length = \strlen($data);

                    if ($length === 0) {
                        $deferred->resolve(0);
                        continue;
                    }

                    // Error reporting suppressed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
                    $written = @\fwrite($resource, $data);

                    if ($written === false || $written === 0) {
                        $message = "Failed to write to STDIN";
                        if ($error = \error_get_last()) {
                            $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
                        }
                        $exception = new ProcessException($message);

                        $deferred->fail($exception);
                        while (!$writes->isEmpty()) { // Empty the write queue and fail all Deferreds.
                            list(, , $deferred) = $writes->shift();
                            $deferred->fail($exception);
                        }
                        $process->kill();
                        return;
                    }

                    if ($length <= $written) {
                        $deferred->resolve($written + $previous);
                        continue;
                    }

                    $data = \substr($data, $written);
                    $writes->unshift([$data, $written + $previous, $deferred]);
                    return;
                }
            } finally {
                if ($writes->isEmpty()) {
                    Loop::disable($watcher);
                }
            }
        });
        Loop::disable($this->stdinWatcher);

        $callback = static function ($watcher, $resource, Emitter $emitter) {
            // Error reporting suppressed since fread() produces a warning if the stream unexpectedly closes.
            if (@\feof($resource) || ($data = @\fread($resource, self::CHUNK_SIZE)) === false) {
                Loop::disable($watcher);
                return;
            }

            if ($data !== "") {
                $emitter->emit($data);
            }
        };

        $this->stdoutWatcher = Loop::onReadable($this->process->getStdout(), $callback, $this->stdoutEmitter);
        $this->stderrWatcher = Loop::onReadable($this->process->getStderr(), $callback, $this->stderrEmitter);

        $this->process->join()->when(function (\Throwable $exception = null, int $code = null) {
            Loop::cancel($this->stdinWatcher);
            Loop::cancel($this->stdoutWatcher);
            Loop::cancel($this->stderrWatcher);

            if ($exception) {
                $this->stdoutEmitter->fail($exception);
                $this->stderrEmitter->fail($exception);
                return;
            }

            $this->stdoutEmitter->resolve($code);
            $this->stderrEmitter->resolve($code);
        });
    }

    public function join(): Promise {
        return $this->process->join();
    }

    /**
     * {@inheritdoc}
     */
    public function isRunning(): bool {
        return $this->process->isRunning();
    }

    /**
     * @param string $data
     *
     * @return \Amp\Promise
     */
    public function write(string $data): Promise {
        $length = \strlen($data);
        $written = 0;

        if ($this->writeQueue->isEmpty()) {
            if ($length === 0) {
                return new Success(0);
            }

            // Error reporting suppressed since fwrite() emits E_WARNING if the pipe is broken or the buffer is full.
            $written = @\fwrite($this->process->getStdIn(), $data);

            if ($written === false) {
                $message = "Failed to write to stream";
                if ($error = \error_get_last()) {
                    $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
                }
                return new Failure(new ProcessException($message));
            }

            if ($length <= $written) {
                return new Success($written);
            }

            $data = \substr($data, $written);
        }

        $deferred = new Deferred;
        $this->writeQueue->push([$data, $written, $deferred]);
        Loop::enable($this->stdinWatcher);
        return $deferred->promise();
    }

    /**
     * Message buffering the output of STDOUT.
     *
     * @return \Amp\Message
     */
    public function getStdout(): Message {
        return $this->stdoutMessage;
    }

    /**
     * Message buffering the output of STDERR.
     *
     * @return \Amp\Message
     */
    public function getStderr(): Message {
        return $this->stderrMessage;
    }

    /**
     * {@inheritdoc}
     */
    public function kill() {
        $this->process->kill();
    }

    /**
     * {@inheritdoc}
     */
    public function getPid(): int {
        return $this->process->getPid();
    }

    /**
     * {@inheritdoc}
     */
    public function signal(int $signo) {
        $this->process->signal($signo);
    }
}
