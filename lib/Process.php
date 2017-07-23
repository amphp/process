<?php

namespace Amp\Process;

use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Deferred;
use Amp\Process\Internal\Posix\Runner as PosixProcessRunner;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\Internal\Windows\Runner as WindowsProcessRunner;
use Amp\Promise;

class Process {
    /** @var ProcessRunner */
    private static $processRunner;

    /** @var resource|null */
    private $process;

    /** @var string */
    private $command;

    /** @var string */
    private $cwd = "";

    /** @var array */
    private $env = [];

    /** @var array */
    private $options;

    /** @var ProcessHandle */
    private $handle;

    /** @var bool */
    private $started = false;

    /** @var Promise */
    private $startPromise;

    /**
     * @param   string|array $command Command to run.
     * @param   string|null $cwd Working directory or use an empty string to use the working directory of the current
     *     PHP process.
     * @param   mixed[] $env Environment variables or use an empty array to inherit from the current PHP process.
     * @param   mixed[] $options Options for proc_open().
     * @throws \Error
     */
    public function __construct($command, string $cwd = null, array $env = [], array $options = []) {
        if (self::$processRunner === null) {
            self::$processRunner = \strncasecmp(\PHP_OS, "WIN", 3) !== 0
                ? new WindowsProcessRunner()
                : new PosixProcessRunner();
        }

        if (\is_array($command)) {
            $command = \implode(" ", \array_map("escapeshellarg", $command));
        }
        $this->command = $command;
        $this->cwd = $cwd ?? "";

        foreach ($env as $key => $value) {
            if (\is_array($value)) {
                throw new \Error("\$env cannot accept array values");
            }

            $this->env[(string) $key] = (string) $value;
        }

        $this->options = $options;
    }

    /**
     * Stops the process if it is still running.
     */
    public function __destruct() {
        if ($this->handle !== null) {
            self::$processRunner->destroy($this->handle);
        }
    }

    /**
     * Resets process values.
     */
    public function __clone() {
        $this->process = null;
        $this->handle = null;
        $this->startPromise = null;
        $this->started = false;
    }

    /**
     * Start the process.
     *
     * @return Promise Fails with a ProcessException if starting the process fails.
     * @throws \Amp\Process\StatusError If the process is already running.
     * @throws \Amp\Process\ProcessException If starting the process fails.
     */
    public function start(): Promise {
        if ($this->started) {
            throw new StatusError("The process has already been started");
        }

        $this->started = true;
        $deferred = new Deferred;

        $info = &$this->handle;
        self::$processRunner->start($this->command, $this->cwd, $this->env, $this->options)
            ->onResolve(static function($error, $procInfo) use($deferred, &$info) {
                if ($error) {
                    $deferred->fail($error);
                    return;
                }

                $info = $procInfo;
                $deferred->resolve();
            });

        return $this->startPromise = $deferred->promise();
    }

    /**
     * Wait for the process to end..
     *
     * @return Promise <int> Succeeds with process exit code or fails with a ProcessException if the process is killed.
     * @throws \Amp\Process\StatusError If the process has not been started.
     */
    public function join(): Promise {
        if (!$this->started) {
            throw new StatusError("The process has not been started");
        }

        if ($this->isRunning()) {
            return self::$processRunner->join($this->handle);
        }

        $deferred = new Deferred;

        $info = &$this->handle;
        $this->startPromise->onResolve(static function($error) use ($info, $deferred) {
            if ($error) {
                $deferred->fail($error);
                return;
            }

            $deferred->resolve(self::$processRunner->join($info));
        });

        return $deferred->promise();
    }

    /**
     * Forcibly end the process.
     *
     * @return void
     * @throws \Amp\Process\StatusError If the process is not running.
     * @throws \Amp\Process\ProcessException If terminating the process fails.
     */
    public function kill() {
        if (!$this->isRunning()) {
            throw new StatusError("The process is not running");
        }

        self::$processRunner->kill($this->handle);
    }

    /**
     * Send a signal signal to the process.
     *
     * @param int $signo Signal number to send to process.
     * @return void
     * @throws \Amp\Process\StatusError If the process is not running.
     * @throws \Amp\Process\ProcessException If sending the signal fails.
     */
    public function signal(int $signo) {
        if (!$this->isRunning()) {
            throw new StatusError("The process is not running");
        }

        self::$processRunner->signal($this->handle, $signo);
    }

    /**
     * Returns the PID of the child process.
     *
     * @return int
     * @throws \Amp\Process\StatusError If the process has not started.
     */
    public function getPid(): int {
        if ($this->handle === null) {
            throw new StatusError("The process has not started");
        }

        return $this->handle->pid;
    }

    /**
     * Returns the command to execute.
     *
     * @return string The command to execute.
     */
    public function getCommand(): string {
        return $this->command;
    }

    /**
     * Gets the current working directory.
     *
     * @return string The current working directory an empty string if inherited from the current PHP process.
     */
    public function getWorkingDirectory(): string {
        if ($this->cwd === "") {
            return \getcwd() ?: "";
        }

        return $this->cwd;
    }

    /**
     * Gets the environment variables array.
     *
     * @return string[] Array of environment variables.
     */
    public function getEnv(): array {
        return $this->env;
    }

    /**
     * Gets the options to pass to proc_open().
     *
     * @return mixed[] Array of options.
     */
    public function getOptions(): array {
        return $this->options;
    }

    /**
     * Determines if the process has been started.
     *
     * @return bool
     */
    public function isStarted(): bool {
        return $this->started;
    }

    /**
     * Determines if the process is still running.
     *
     * @return bool
     */
    public function isRunning(): bool {
        return ($this->handle->status ?? null) === ProcessHandle::STATUS_RUNNING;
    }

    /**
     * Gets the process input stream (STDIN).
     *
     * @return \Amp\ByteStream\ResourceOutputStream
     * @throws \Amp\Process\StatusError If the process is not running.
     */
    public function getStdin(): ResourceOutputStream {
        if (!$this->isRunning()) {
            throw new StatusError("The process is not running");
        }

        return $this->handle->stdin;
    }

    /**
     * Gets the process output stream (STDOUT).
     *
     * @return \Amp\ByteStream\ResourceInputStream
     * @throws \Amp\Process\StatusError If the process is not running.
     */
    public function getStdout(): ResourceInputStream {
        if (!$this->isRunning()) {
            throw new StatusError("The process is not running");
        }

        return $this->handle->stdout;
    }

    /**
     * Gets the process error stream (STDERR).
     *
     * @return \Amp\ByteStream\ResourceInputStream
     * @throws \Amp\Process\StatusError If the process is not running.
     */
    public function getStderr(): ResourceInputStream {
        if (!$this->isRunning()) {
            throw new StatusError("The process is not running");
        }

        return $this->handle->stderr;
    }
}
