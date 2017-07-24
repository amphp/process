<?php declare(strict_types=1);

namespace Amp\Process\Internal\Posix;

use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Loop;
use Amp\Process\Internal\ProcessHandle;
use Amp\Process\Internal\ProcessRunner;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\ProcessException;
use Amp\Promise;

final class Runner implements ProcessRunner
{
    const FD_SPEC = [
        ["pipe", "r"], // stdin
        ["pipe", "w"], // stdout
        ["pipe", "w"], // stderr
        ["pipe", "w"], // exit code pipe
    ];

    public function onProcessEndExtraDataPipeReadable($watcher, $stream, Handle $handle) {
        Loop::cancel($watcher);

        $handle->status = ProcessStatus::ENDED;

        if (!\is_resource($stream) || \feof($stream)) {
            $handle->endDeferred->fail(new ProcessException("Process ended unexpectedly"));
        } else {
            $handle->endDeferred->resolve((int) \rtrim(@\stream_get_contents($stream)));
        }
    }

    public function onProcessStartExtraDataPipeReadable($watcher, $stream, Handle $handle) {
        Loop::cancel($watcher);

        $pid = \rtrim(@\fgets($stream));

        if (!$pid || !\is_numeric($pid)) {
            $handle->startDeferred->fail(new ProcessException("Could not determine PID"));
            return;
        }

        $handle->status = ProcessStatus::RUNNING;
        $handle->pid = (int) $pid;
        $handle->stdin = new ResourceOutputStream($handle->pipes[0]);
        $handle->stdout = new ResourceInputStream($handle->pipes[1]);
        $handle->stderr = new ResourceInputStream($handle->pipes[2]);

        $handle->extraDataPipeWatcher = Loop::onReadable($stream, [$this, 'onProcessEndExtraDataPipeReadable'], $handle);
        Loop::unreference($handle->extraDataPipeWatcher);
    }

    /**
     * {@inheritdoc}
     */
    public function start(string $command, string $cwd = null, array $env = [], array $options = []): Promise {
        $command = \sprintf(
            '{ (%s) <&3 3<&- 3>/dev/null & } 3<&0;' .
            'pid=$!; echo $pid >&3; wait $pid; RC=$?; echo $RC >&3; exit $RC',
            $command
        );

        $handle = new Handle;

        $handle->proc = @\proc_open($command, self::FD_SPEC, $handle->pipes, $cwd ?: null, $env ?: null, $options);

        if (!\is_resource($handle->proc)) {
            $message = "Could not start process";
            if ($error = \error_get_last()) {
                $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
            }
            throw new ProcessException($message);
        }

        $status = \proc_get_status($handle->proc);

        if (!$status) {
            \proc_close($handle->proc);
            throw new ProcessException("Could not get process status");
        }

        \stream_set_blocking($handle->pipes[3], false);

        /* It's fine to use an instance method here because this object is assigned to a static var in Process and never
           needs to be dtor'd before the process ends */
        Loop::onReadable($handle->pipes[3], [$this, 'onProcessStartExtraDataPipeReadable'], $handle);

        return $handle->startDeferred->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function join(ProcessHandle $handle): Promise {
        /** @var Handle $handle */

        if ($handle->extraDataPipeWatcher !== null) {
            Loop::reference($handle->extraDataPipeWatcher);
        }

        return $handle->endDeferred->promise();
    }

    /**
     * {@inheritdoc}
     */
    public function kill(ProcessHandle $handle) {
        /** @var Handle $handle */

        // Forcefully kill the process using SIGKILL.
        if (!\proc_terminate($handle->proc, 9)) {
            throw new ProcessException("Terminating process failed");
        }

        Loop::cancel($handle->extraDataPipeWatcher);
        $handle->extraDataPipeWatcher = null;

        $handle->status = ProcessStatus::ENDED;

        $handle->endDeferred->fail(new ProcessException("The process was killed"));
    }

    /**
     * {@inheritdoc}
     */
    public function signal(ProcessHandle $handle, int $signo) {
        /** @var Handle $handle */

        if (!\proc_terminate($handle->proc, $signo)) {
            throw new ProcessException("Sending signal to process failed");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(ProcessHandle $handle) {
        /** @var Handle $handle */

        if (\getmypid() === $handle->originalParentPid && $handle->status < ProcessStatus::ENDED) {
            $this->kill($handle);
        }

        if ($handle->extraDataPipeWatcher !== null) {
            Loop::cancel($handle->extraDataPipeWatcher);
        }

        for ($i = 0; $i < 4; $i++) {
            if (\is_resource($handle->pipes[$i] ?? null)) {
                \fclose($handle->pipes[$i]);
            }
        }

        if (\is_resource($handle->proc)) {
            \proc_close($handle->proc);
        }
    }
}
