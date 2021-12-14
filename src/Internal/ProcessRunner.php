<?php

namespace Amp\Process\Internal;

use Amp\Process\ProcessException;

/** @internal */
interface ProcessRunner
{
    /**
     * Start a process using the supplied parameters.
     *
     * @param string $command The command to execute.
     * @param string|null $workingDirectory The working directory for the child process.
     * @param array $environment Environment variables to pass to the child process.
     * @param array $options `proc_open()` options.
     *
     * @return ProcessHandle
     *
     * @throws ProcessException If starting the process fails.
     */
    public function start(
        string $command,
        string $workingDirectory = null,
        array $environment = [],
        array $options = []
    ): ProcessHandle;

    /**
     * Wait for the child process to end.
     *
     * @param ProcessHandle $handle The process descriptor.
     *
     * @return int Exit code.
     */
    public function join(ProcessHandle $handle): int;

    /**
     * Forcibly end the child process.
     *
     * @param ProcessHandle $handle The process descriptor.
     *
     * @throws ProcessException If terminating the process fails.
     */
    public function kill(ProcessHandle $handle): void;

    /**
     * Send a signal to the child process.
     *
     * @param ProcessHandle $handle The process descriptor.
     * @param int $signal Signal number to send to process.
     *
     * @throws ProcessException If sending the signal fails.
     */
    public function signal(ProcessHandle $handle, int $signal): void;

    /**
     * Release all resources held by the process handle.
     *
     * @param ProcessHandle $handle The process descriptor.
     */
    public function destroy(ProcessHandle $handle): void;
}
