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

    /**
     * @param string $command Command to run.
     * @param string $cwd Working directory of child process.
     * @param array $env Environment variables for child process.
     * @param array $options Options for proc_open().
     * @param ProcessHandle $handle Handle for the created process.
     */
    private function __construct(string $command, string $cwd, array $env, array $options, ProcessHandle $handle) {
        $this->command = $command;
        $this->cwd = $cwd;
        $this->env = $env;
        $this->options = $options;
        $this->handle = $handle;
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
    public function clone(): Promise {
        return self::start($this->command, $this->cwd, $this->env, $this->options);
    }

    /**
     * Start a new process.
     *
     * @param   string|string[] $command Command to run.
     * @param   string|null $cwd Working directory or use an empty string to use the working directory of the current
     *     PHP process.
     * @param   mixed[] $env Environment variables or use an empty array to inherit from the current PHP process.
     * @param   mixed[] $options Options for proc_open().
     * @return Promise <Process> Fails with a ProcessException if starting the process fails.
     * @throws \Error If the arguments are invalid.
     * @throws \Amp\Process\StatusError If the process is already running.
     * @throws \Amp\Process\ProcessException If starting the process fails.
     */
    public static function start($command, string $cwd = null, array $env = [], array $options = []): Promise {
        $command = \is_array($command)
            ? \implode(" ", \array_map("escapeshellarg", $command))
            : (string) $command;

        $cwd = $cwd ?? "";

        $envVars = [];
        foreach ($env as $key => $value) {
            if (\is_array($value)) {
                throw new \Error("\$env cannot accept array values");
            }

            $envVars[(string) $key] = (string) $value;
        }

        $deferred = new Deferred;

        self::$processRunner->start($command, $cwd, $env, $options)
            ->onResolve(function($error, $handle) use($deferred, $command, $cwd, $env, $options) {
                if ($error) {
                    $deferred->fail($error);
                    return;
                } else {
                    $deferred->resolve(new Process($command, $cwd, $env, $options, $handle));
                }
            });

        return $deferred->promise();
    }

    /**
     * Wait for the process to end..
     *
     * @return Promise <int> Succeeds with process exit code or fails with a ProcessException if the process is killed.
     */
    public function join(): Promise {
        return self::$processRunner->join($this->handle);
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

(function() {
    /** @noinspection PhpUndefinedClassInspection */
    self::$processRunner = \strncasecmp(\PHP_OS, "WIN", 3) !== 0
        ? new WindowsProcessRunner()
        : new PosixProcessRunner();
})->bindTo(null, Process::class)();
