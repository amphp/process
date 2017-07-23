<?php declare(strict_types=1);

namespace Amp\Process\Internal\Posix;

use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Loop;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\ProcessException;
use Amp\Promise;

final class Runner implements ProcessRunner
{
    public function onProcessEndPipeReadable($watcher, $stream, Handle $process) {
        Loop::cancel($watcher);

        $process->status = ProcessHandle::STATUS_ENDED;

        if (!\is_resource($stream) || \feof($stream)) {
            $process->endDeferred->fail(new ProcessException("Process ended unexpectedly"));
        } else {
            $process->endDeferred->resolve((int) \rtrim(@\stream_get_contents($stream)));
        }
    }

    public function onProcessStartPipeReadable($watcher, $stream, Handle $process) {
        Loop::cancel($watcher);

        $pid = \rtrim(@\fgets($stream));

        if (!$pid || !\is_numeric($pid)) {
            $process->startDeferred->fail(new ProcessException("Could not determine PID"));
            return;
        }

        $process->status = ProcessHandle::STATUS_RUNNING;
        $process->pid = (int) $pid;
        $process->stdin = new ResourceOutputStream($process->pipes[0]);
        $process->stdout = new ResourceInputStream($process->pipes[1]);
        $process->stderr = new ResourceInputStream($process->pipes[2]);

        $process->extraDataPipeWatcher = Loop::onReadable($stream, [$this, 'onProcessEndPipeReadable'], $process);
        Loop::unreference($process->extraDataPipeWatcher);
    }

    /**
     * {@inheritdoc}
     */
    public function start(string $command, string $cwd = null, array $env = [], array $options = []): Promise {
        $fd = [
            ["pipe", "r"], // stdin
            ["pipe", "w"], // stdout
            ["pipe", "w"], // stderr
            ["pipe", "w"], // exit code pipe
        ];

        $command = \sprintf(
            '{ (%s) <&3 3<&- 3>/dev/null & } 3<&0;' .
            'pid=$!; echo $pid >&3; wait $pid; RC=$?; echo $RC >&3; exit $RC',
            $command
        );

        $process = new Handle;

        $process->handle = @\proc_open($command, $fd, $process->pipes, $cwd ?: null, $env ?: null, $options);

        if (!\is_resource($process->handle)) {
            $message = "Could not start process";
            if ($error = \error_get_last()) {
                $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
            }
            throw new ProcessException($message);
        }

        $status = \proc_get_status($process->handle);

        if (!$status) {
            \proc_close($process->handle);
            throw new ProcessException("Could not get process status");
        }

        \stream_set_blocking($process->pipes[3], false);

        /* It's fine to use an instance method here because this object is assigned to a static var in Process and never
           needs to be dtor'd before the process ends */
        Loop::onReadable($process->pipes[3], [$this, 'onProcessStartPipeReadable'], $process);

        return $process->startDeferred->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function join(ProcessHandle $process): Promise {
        /** @var Handle $process */

        if ($process->extraDataPipeWatcher !== null) {
            Loop::reference($process->extraDataPipeWatcher);
        }

        return $process->endDeferred->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function kill(ProcessHandle $process) {
        /** @var Handle $process */

        // Forcefully kill the process using SIGKILL.
        if (!\proc_terminate($process->handle, 9)) {
            throw new ProcessException("Terminating process failed");
        }

        Loop::cancel($process->extraDataPipeWatcher);
        $process->extraDataPipeWatcher = null;

        $process->status = ProcessHandle::STATUS_ENDED;

        $process->endDeferred->fail(new ProcessException("The process was killed"));
    }

    /**
     * {@inheritdoc}
     */
    public function signal(ProcessHandle $process, int $signo) {
        /** @var Handle $process */

        if (!\proc_terminate($process->handle, $signo)) {
            throw new ProcessException("Sending signal to process failed");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(ProcessHandle $process) {
        /** @var Handle $process */

        if (\getmypid() === $process->originalParentPid && $process->status < ProcessHandle::STATUS_ENDED) {
            $this->kill($process);
        }

        if ($process->extraDataPipeWatcher !== null) {
            Loop::cancel($process->extraDataPipeWatcher);
        }

        for ($i = 0; $i < 4; $i++) {
            if (\is_resource($process->pipes[$i] ?? null)) {
                \fclose($process->pipes[$i]);
            }
        }

        if (\is_resource($process->handle)) {
            \proc_close($process->handle);
        }
    }
}
