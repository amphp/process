<?php

namespace Amp\Process;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\Process\Internal\Posix\PosixRunner as PosixProcessRunner;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\Internal\Windows\WindowsRunner as WindowsProcessRunner;
use JetBrains\PhpStorm\ArrayShape;
use Revolt\EventLoop;

final class Process
{
    private static \WeakMap $driverRunner;

    private ProcessRunner $processRunner;

    private string $command;

    private ?string $workingDirectory;

    /** @var string[] */
    private array $environment;

    private array $options;

    private ?ProcessHandle $handle = null;

    private bool $started = false;

    private ?int $pid = null;

    /**
     * @param string|string[] $command Command to run.
     * @param string|null $workingDirectory Working directory, or an empty string to use the working directory of the
     *     parent.
     * @param string[] $environment Environment variables, or use an empty array to inherit from the parent.
     * @param array $options Options for `proc_open()`.
     *
     * @throws \Error If the arguments are invalid.
     */
    public function __construct(
        string|array $command,
        string $workingDirectory = null,
        array $environment = [],
        array $options = []
    ) {
        self::$driverRunner ??= new \WeakMap();

        $envVars = [];
        foreach ($environment as $key => $value) {
            if (\is_array($value)) {
                throw new \Error('Argument #3 ($environment) cannot accept nested array values');
            }

            $envVars[(string) $key] = (string) $value;
        }

        $this->command = \is_array($command)
            ? \implode(" ", \array_map(__NAMESPACE__ . "\\escapeArguments", $command))
            : $command;
        $this->workingDirectory = $workingDirectory;
        $this->environment = $envVars;
        $this->options = $options;

        $driver = EventLoop::getDriver();
        self::$driverRunner[$driver] ??= \PHP_OS_FAMILY === 'Windows'
            ? new WindowsProcessRunner()
            : new PosixProcessRunner();

        $this->processRunner = self::$driverRunner[$driver];
    }

    /**
     * Stops the process if it is still running.
     */
    public function __destruct()
    {
        if ($this->handle !== null) {
            $this->processRunner->destroy($this->handle);
        }
    }

    public function __clone()
    {
        throw new \Error("Cloning " . self::class . " is not allowed.");
    }

    /**
     * Start the process.
     *
     * @throws StatusError If the process has already been started.
     */
    public function start(): void
    {
        if ($this->started) {
            throw new StatusError("Process has already been started.");
        }

        $this->started = true;
        $this->handle = $this->processRunner->start(
            $this->command,
            $this->workingDirectory,
            $this->environment,
            $this->options
        );

        $this->pid = $this->handle->pid;
    }

    /**
     * Wait for the process to end.
     *
     * @return int The process exit code.
     *
     * @throws ProcessException If the process is killed.
     * @throws StatusError If the process has not been started, yet.
     */
    public function join(): int
    {
        if (!$this->handle) {
            throw new StatusError("Process has not been started.");
        }

        return $this->processRunner->join($this->handle);
    }

    /**
     * Forcibly end the process.
     *
     * @throws StatusError If the process is not running.
     * @throws ProcessException If terminating the process fails.
     */
    public function kill(): void
    {
        if (!$this->isRunning()) {
            throw new StatusError("Process is not running.");
        }

        $this->processRunner->kill($this->handle);
    }

    /**
     * Send a signal to the process.
     *
     * @param int $signo Signal number to send to process.
     *
     * @throws StatusError If the process is not running.
     * @throws ProcessException If sending the signal fails.
     */
    public function signal(int $signo): void
    {
        if (!$this->isRunning()) {
            throw new StatusError("Process is not running.");
        }

        $this->processRunner->signal($this->handle, $signo);
    }

    /**
     * Returns the PID of the child process.
     *
     * @return int
     *
     * @throws StatusError If the process has not started or has not completed starting.
     */
    public function getPid(): int
    {
        if (!$this->pid) {
            throw new StatusError("Process has not been started or has not completed starting.");
        }

        return $this->pid;
    }

    /**
     * Returns the command to execute.
     *
     * @return string The command to execute.
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * Gets the current working directory.
     *
     * @return string|null The current working directory or null if inherited from the current PHP process.
     */
    public function getWorkingDirectory(): ?string
    {
        return $this->workingDirectory;
    }

    /**
     * Gets the environment variables array.
     *
     * @return string[] Array of environment variables.
     */
    public function getEnvironment(): array
    {
        return $this->environment;
    }

    /**
     * Gets the options to pass to proc_open().
     *
     * @return array Array of options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Determines if the process is still running.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->handle && $this->handle->status !== ProcessStatus::ENDED;
    }

    /**
     * Gets the process input stream (STDIN).
     */
    public function getStdin(): WritableResourceStream
    {
        if (!$this->handle) {
            throw new StatusError("Process has not been started or has not completed starting.");
        }

        return $this->handle->stdin;
    }

    /**
     * Gets the process output stream (STDOUT).
     */
    public function getStdout(): ReadableResourceStream
    {
        if (!$this->handle) {
            throw new StatusError("Process has not been started or has not completed starting.");
        }

        return $this->handle->stdout;
    }

    /**
     * Gets the process error stream (STDERR).
     */
    public function getStderr(): ReadableResourceStream
    {
        if (!$this->handle) {
            throw new StatusError("Process has not been started or has not completed starting.");
        }

        return $this->handle->stderr;
    }

    #[ArrayShape([
        'command' => "string",
        'workingDirectory' => "null|string",
        'environment' => "string[]",
        'options' => "array",
        'pid' => "int|null",
        'status' => "int",
    ])]
    public function __debugInfo(): array
    {
        return [
            'command' => $this->getCommand(),
            'workingDirectory' => $this->getWorkingDirectory(),
            'environment' => $this->getEnvironment(),
            'options' => $this->getOptions(),
            'pid' => $this->pid,
            'status' => $this->handle->status ?? -1,
        ];
    }
}
