<?php declare(strict_types=1);

namespace Amp\Process\Internal\Windows;

use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\ProcessException;
use Amp\Promise;

final class Runner implements ProcessRunner
{
    const WRAPPER_EXE_PATH = __DIR__ . '\\..\\bin\\windows\\ProcessWrapper.exe';

    /**
     * {@inheritdoc}
     */
    public function start(string $command, string $cwd = null, array $env = [], array $options = []): Promise
    {
        // TODO: Implement start() method.
    }

    /**
     * {@inheritdoc}
     */
    public function join(ProcessHandle $process): Promise
    {
        // TODO: Implement join() method.
    }

    /**
     * {@inheritdoc}
     */
    public function kill(ProcessHandle $process)
    {
        // TODO: Implement kill() method.
    }

    /**
     * {@inheritdoc}
     */
    public function signal(ProcessHandle $process, int $signo)
    {
        throw new ProcessException('Signals are not supported on Windows');
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(ProcessHandle $process)
    {
        // TODO: Implement destroy() method.
    }
}
