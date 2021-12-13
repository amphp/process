<?php

namespace Amp\Process\Test;

use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\Process;
use Amp\Process\StatusError;
use const Amp\Process\IS_WINDOWS;
use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\delay;

class ProcessTest extends AsyncTestCase
{
    private const CMD_PROCESS = \DIRECTORY_SEPARATOR === "\\" ? "cmd /c echo foo" : "echo foo";
    private const CMD_PROCESS_SLOW = \DIRECTORY_SEPARATOR === "\\" ? "cmd /c ping -n 3 127.0.0.1 > nul" : "sleep 2";
    private const CMD_PROCESS_STDIN = (\DIRECTORY_SEPARATOR === "\\" ? 'php.exe "' : 'php "') . __DIR__ . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'worker.php"';
    private const CMD_PROCESS_STDERR = \DIRECTORY_SEPARATOR === "\\" ? "cmd /c >&2 echo foo" : ">&2 echo foo";

    public function testMultipleExecution(): void
    {
        $process = new Process(self::CMD_PROCESS);
        $process->start();

        try {
            $this->expectException(StatusError::class);

            $process->start();
        } finally {
            $process->join();
        }
    }

    public function testIsRunning(): void
    {
        $process = new Process(\DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit 42" : "exit 42");
        $process->start();
        $future = async(fn () => $process->join());

        self::assertTrue($process->isRunning());

        $future->await();

        self::assertFalse($process->isRunning());
    }

    public function testExecuteResolvesToExitCode(): void
    {
        $process = new Process(\DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit 42" : "exit 42");
        $process->start();

        $code = $process->join();

        self::assertSame(42, $code);
        self::assertFalse($process->isRunning());
    }

    public function testCommandCanRun(): void
    {
        $process = new Process(self::CMD_PROCESS);
        $process->start();

        self::assertSame(0, $process->join());
    }

    public function testProcessCanTerminate(): void
    {
        if (\DIRECTORY_SEPARATOR === "\\") {
            self::markTestSkipped("Signals are not supported on Windows");
        }

        $process = new Process(self::CMD_PROCESS_SLOW);
        $process->start();
        $process->signal(0);
        self::assertSame(0, $process->join());
    }

    public function testGetWorkingDirectoryIsDefault(): void
    {
        $process = new Process(self::CMD_PROCESS);
        self::assertNull($process->getWorkingDirectory());
    }

    public function testGetWorkingDirectoryIsCustomized(): void
    {
        $process = new Process(self::CMD_PROCESS, __DIR__);
        self::assertSame(__DIR__, $process->getWorkingDirectory());
    }

    public function testGetEnv(): void
    {
        $process = new Process(self::CMD_PROCESS);
        self::assertSame([], $process->getEnvironment());
    }

    public function testGetStdin(): void
    {
        $process = new Process(self::CMD_PROCESS_STDIN);
        $process->start();

        $process->getStdin()->write('exit 5');
        $process->getStdin()->end();

        self::assertSame('.....', buffer($process->getStdout()));

        $process->join();
    }

    public function testGetStdout(): void
    {
        $process = new Process(self::CMD_PROCESS);
        $process->start();

        self::assertSame('foo' . \PHP_EOL, buffer($process->getStdout()));

        $process->join();
    }

    public function testGetStderr(): void
    {
        $process = new Process(self::CMD_PROCESS_STDERR);
        $process->start();

        self::assertSame('foo' . \PHP_EOL, buffer($process->getStderr()));

        $process->join();
    }

    public function testProcessEnvIsValid(): void
    {
        $process = new Process(self::CMD_PROCESS, null, [
            'test' => 'foobar',
            'PATH' => \getenv('PATH'),
            'SystemRoot' => \getenv('SystemRoot') ?: '', // required on Windows for process wrapper
        ]);
        $process->start();
        self::assertSame('foobar', $process->getEnvironment()['test']);
        $process->join();
    }

    public function testProcessEnvIsInvalid(): void
    {
        $this->expectException(\Error::class);

        /** @noinspection PhpParamsInspection */
        new Process(self::CMD_PROCESS, null, [
            ['error_value'],
        ]);
    }

    public function testGetStdinIsStatusError(): void
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process has not been started or has not completed starting');

        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStdin();
    }

    public function testGetStdoutIsStatusError(): void
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process has not been started or has not completed starting');

        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStdout();
    }

    public function testGetStderrIsStatusError(): void
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process has not been started or has not completed starting');

        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStderr();
    }

    public function testProcessCantBeCloned(): void
    {
        $process = new Process(self::CMD_PROCESS);

        $this->expectException(\Error::class);

        /** @noinspection PhpExpressionResultUnusedInspection */
        clone $process;
    }

    public function testKillImmediately(): void
    {
        $this->setTimeout(1);

        $process = new Process(self::CMD_PROCESS_SLOW);
        $process->start();
        $process->kill();

        self::assertSame(IS_WINDOWS ? 1 : 137, $process->join());
    }

    public function testKillThenReadStdout(): void
    {
        $this->setTimeout(1);

        $process = new Process(self::CMD_PROCESS_SLOW);
        $process->start();
        $process->kill();

        self::assertNull($process->getStdout()->read());
        self::assertSame(IS_WINDOWS ? 1 : 137, $process->join());
    }

    public function testProcessHasNotBeenStartedWithJoin(): void
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process has not been started');

        $process = new Process(self::CMD_PROCESS);
        $process->join();
    }

    public function testProcessHasNotBeenStartedWithGetPid(): void
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process has not been started');

        $process = new Process(self::CMD_PROCESS);
        $process->getPid();
    }

    public function testProcessIsNotRunningWithKill(): void
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process has not been started');

        $process = new Process(self::CMD_PROCESS);
        $process->kill();
    }

    public function testProcessIsNotRunningWithSignal(): void
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process has not been started');

        $process = new Process(self::CMD_PROCESS);
        $process->signal(0);
    }

    public function testProcessHasBeenStarted(): void
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process has not been started');

        $process = new Process(self::CMD_PROCESS);
        $process->join();
    }

    public function testCommand(): void
    {
        $process = new Process([self::CMD_PROCESS]);
        self::assertSame(\implode(" ", \array_map("escapeshellarg", [self::CMD_PROCESS])), $process->getCommand());
    }

    public function testOptions(): void
    {
        $process = new Process(self::CMD_PROCESS);
        self::assertSame([], $process->getOptions());
    }

    public function getProcessCounts(): array
    {
        return \array_map(function (int $count): array {
            return [$count];
        }, \range(2, 32, 2));
    }

    /**
     * @dataProvider getProcessCounts
     *
     * @param int $count
     */
    public function testSpawnMultipleProcesses(int $count): void
    {
        $processes = [];
        for ($i = 0; $i < $count; ++$i) {
            $command = \DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit $i" : "exit $i";
            $processes[] = new Process(self::CMD_PROCESS_SLOW . " && " . $command);
        }

        $promises = [];
        foreach ($processes as $process) {
            $process->start();
            $promises[] = async(fn () => $process->join());
        }

        self::assertEquals(\range(0, $count - 1), Future\all($promises));
    }

    public function testReadOutputAfterExit(): void
    {
        $process = new Process(["php", __DIR__ . "/bin/worker.php"]);
        $process->start();

        $process->getStdin()->write("exit 2");
        self::assertSame("..", $process->getStdout()->read());

        self::assertSame(0, $process->join());
    }

    public function testReadOutputAfterExitWithLongOutput(): void
    {
        $process = new Process(["php", __DIR__ . "/bin/worker.php"]);
        $process->start();

        $count = 128 * 1024 + 1;
        $process->getStdin()->write("exit " . $count);
        self::assertSame(\str_repeat(".", $count), buffer($process->getStdout()));

        self::assertSame(0, $process->join());
    }

    public function testKillPHPImmediately(): void
    {
        $socket = \stream_socket_server("tcp://127.0.0.1:10000");
        self::assertNotFalse($socket);
        $process = new Process(["php", __DIR__ . "/bin/socket-worker.php"]);
        $process->start();
        $conn = \stream_socket_accept($socket);
        self::assertSame('start', \fread($conn, 5));
        $process->kill();
        delay(0); // Tick event loop to send signal
        self::assertEmpty(\fread($conn, 3));
        $process->join();
    }

    /**
     * @requires extension pcntl
     */
    public function testSignal(): void
    {
        $process = new Process(["php", __DIR__ . "/bin/signal-process.php"]);
        $process->start();
        delay(0.1); // Give process time to set up single handler.
        $process->signal(\SIGTERM);

        self::assertSame(42, $process->join());
    }

    public function testDebugInfo(): void
    {
        $process = new Process(["php", __DIR__ . "/bin/worker.php"], __DIR__);

        self::assertSame([
            'command' => IS_WINDOWS
                ? "\"php\" \"" . __DIR__ . "/bin/worker.php\""
                : "'php' '" . __DIR__ . "/bin/worker.php'",
            'workingDirectory' => __DIR__,
            'environment' => [],
            'options' => [],
            'pid' => null,
            'status' => -1,
        ], $process->__debugInfo());

        $process->start();

        $debug = $process->__debugInfo();

        self::assertIsInt($debug['pid']);
        self::assertSame(ProcessStatus::RUNNING, $debug['status']);
    }
}
