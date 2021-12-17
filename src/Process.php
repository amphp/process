<?php

namespace Amp\Process;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\Process\Internal\Posix\PosixRunner as PosixProcessRunner;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\Internal\ProcHolder;
use Amp\Process\Internal\Windows\WindowsRunner as WindowsProcessRunner;
use JetBrains\PhpStorm\ArrayShape;
use Revolt\EventLoop;

final class Process
{
    private static \WeakMap $driverRunner;

    private static \WeakMap $procHolder;

    /**
     * Starts a new process.
     *
     * @param string|string[] $command Command to run.
     * @param string|null $workingDirectory Working directory, or an empty string to use the working directory of the
     *     parent.
     * @param string[] $environment Environment variables, or use an empty array to inherit from the parent.
     * @param array $options Options for `proc_open()`.
     *
     * @throws \Error If the arguments are invalid.
     */
    public static function start(
        string|array $command,
        string $workingDirectory = null,
        array $environment = [],
        array $options = []
    ): self {
        $envVars = [];
        foreach ($environment as $key => $value) {
            if (\is_array($value)) {
                throw new \Error('Argument #3 ($environment) cannot accept nested array values');
            }

            /** @psalm-suppress RedundantCastGivenDocblockType */
            $envVars[(string) $key] = (string) $value;
        }

        $command = \is_array($command)
            ? \implode(" ", \array_map(__NAMESPACE__ . "\\escapeArgument", $command))
            : $command;

        $driver = EventLoop::getDriver();

        /** @psalm-suppress RedundantPropertyInitializationCheck */
        self::$driverRunner ??= new \WeakMap();
        self::$driverRunner[$driver] ??= \PHP_OS_FAMILY === 'Windows'
            ? new WindowsProcessRunner()
            : new PosixProcessRunner();

        if (!$workingDirectory) {
            $cwd = \getcwd();
            if ($cwd === false) {
                throw new ProcessException('Failed to determine current working directory');
            }

            $workingDirectory = $cwd;
        }

        $runner = self::$driverRunner[$driver];
        $handle = $runner->start(
            $command,
            $workingDirectory,
            $envVars,
            $options
        );

        $procHolder = new ProcHolder($runner, $handle);

        /** @psalm-suppress RedundantPropertyInitializationCheck */
        self::$procHolder ??= new \WeakMap();
        self::$procHolder[$handle->stdin] = $procHolder;
        self::$procHolder[$handle->stdout] = $procHolder;
        self::$procHolder[$handle->stderr] = $procHolder;

        return new self($runner, $handle, $command, $workingDirectory, $envVars, $options);
    }

    private ProcessRunner $runner;

    private ProcessHandle $handle;

    private string $command;

    private string $workingDirectory;

    /** @var string[] */
    private array $environment;

    private array $options;

    private function __construct(
        ProcessRunner $runner,
        ProcessHandle $handle,
        string $command,
        string $workingDirectory,
        array $environment = [],
        array $options = []
    ) {
        $this->runner = $runner;
        $this->handle = $handle;
        $this->command = $command;
        $this->workingDirectory = $workingDirectory;
        $this->environment = $environment;
        $this->options = $options;
    }

    public function __clone()
    {
        throw new \Error(self::class . " does not support cloning");
    }

    /**
     * Wait for the process to end.
     *
     * @return int The process exit code.
     *
     * @throws ProcessException If the process is killed.
     */
    public function join(): int
    {
        return $this->runner->join($this->handle);
    }

    /**
     * Forcibly end the process.
     *
     * @throws ProcessException If terminating the process fails.
     */
    public function kill(): void
    {
        if (!$this->isRunning()) {
            return;
        }

        $this->runner->kill($this->handle);
        $this->join();
    }

    /**
     * Send a signal to the process.
     *
     * @param int $signo Signal number to send to process.
     *
     * @throws ProcessException If signal sending is not supported.
     */
    public function signal(int $signo): void
    {
        if (!$this->isRunning()) {
            return;
        }

        $this->runner->signal($this->handle, $signo);
    }

    /**
     * Returns the PID of the child process.
     *
     * @return int
     */
    public function getPid(): int
    {
        return $this->handle->pid;
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
     * @return string The working directory.
     */
    public function getWorkingDirectory(): string
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
        return $this->handle->status !== ProcessStatus::ENDED;
    }

    /**
     * Gets the process input stream (STDIN).
     */
    public function getStdin(): WritableResourceStream
    {
        return $this->handle->stdin;
    }

    /**
     * Gets the process output stream (STDOUT).
     */
    public function getStdout(): ReadableResourceStream
    {
        return $this->handle->stdout;
    }

    /**
     * Gets the process error stream (STDERR).
     */
    public function getStderr(): ReadableResourceStream
    {
        return $this->handle->stderr;
    }

    #[ArrayShape([
        'command' => "string",
        'workingDirectory' => "string",
        'environment' => "string[]",
        'options' => "array",
        'pid' => "int",
        'status' => "string",
    ])]
    public function __debugInfo(): array
    {
        return [
            'command' => $this->getCommand(),
            'workingDirectory' => $this->getWorkingDirectory(),
            'environment' => $this->getEnvironment(),
            'options' => $this->getOptions(),
            'pid' => $this->handle->pid,
            'status' => $this->isRunning() ? 'running' : 'terminated',
        ];
    }
}
