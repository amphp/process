<?php declare(strict_types=1);

namespace Amp\Process\Internal;

use Amp\Promise;

interface ProcessRunner
{
    /**
     * Start a process using the supplied parameters
     *
     * @param string $command The command to execute
     * @param string|null $cwd The working directory for the child process
     * @param array $env Environment variables to pass to the child process
     * @param array $options proc_open() options
     * @return Promise <ProcessInfo> Succeeds with a process descriptor or fails if the process cannot be started
     * @throws \Amp\Process\ProcessException If starting the process fails.
     */
    function start(string $command, string $cwd = null, array $env = [], array $options = []): Promise;

    /**
     * Wait for the child process to end
     *
     * @param ProcessHandle $handle The process descriptor
     * @return Promise <int> Succeeds with exit code of the process or fails if the process is killed.
     */
    function join(ProcessHandle $handle): Promise;

    /**
     * Forcibly end the child process
     *
     * @param ProcessHandle $handle The process descriptor
     * @return void
     * @throws \Amp\Process\ProcessException If terminating the process fails
     */
    function kill(ProcessHandle $handle);

    /**
     * Send a signal signal to the child process
     *
     * @param ProcessHandle $handle The process descriptor
     * @param int $signo Signal number to send to process.
     * @return void
     * @throws \Amp\Process\ProcessException If sending the signal fails.
     */
    function signal(ProcessHandle $handle, int $signo);

    /**
     * Release all resources held by the process handle
     *
     * @param ProcessHandle $handle The process descriptor
     * @return void
     */
    function destroy(ProcessHandle $handle);
}
