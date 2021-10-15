<?php

namespace Amp\Process\Test;

use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use Amp\Process\ProcessInputStream;
use Amp\Process\ProcessOutputStream;
use Amp\Process\StatusError;
use const Amp\Process\IS_WINDOWS;
use function Amp\coroutine;
use function Amp\delay;
use function Amp\ByteStream\buffer;

class ProcessTest extends AsyncTestCase
{
    const CMD_PROCESS = \DIRECTORY_SEPARATOR === "\\" ? "cmd /c echo foo" : "echo foo";
    const CMD_PROCESS_SLOW = \DIRECTORY_SEPARATOR === "\\" ? "cmd /c ping -n 3 127.0.0.1 > nul" : "sleep 2";

    public function testMultipleExecution()
    {
        $this->expectException(StatusError::class);

        $process = new Process(self::CMD_PROCESS);
        $process->start();
        $process->start();
    }

    public function testIsRunning()
    {
        $process = new Process(\DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit 42" : "exit 42");
        $process->start();
        $future = coroutine(fn () => $process->join());

        self::assertTrue($process->isRunning());

        $future->await();

        self::assertFalse($process->isRunning());
    }

    public function testExecuteResolvesToExitCode()
    {
        $process = new Process(\DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit 42" : "exit 42");
        $process->start();

        $code = $process->join();

        self::assertSame(42, $code);
        self::assertFalse($process->isRunning());
    }

    public function testCommandCanRun()
    {
        $process = new Process(self::CMD_PROCESS);
        self::assertIsInt($process->start());
        self::assertSame(0, $process->join());
    }

    public function testProcessCanTerminate()
    {
        if (\DIRECTORY_SEPARATOR === "\\") {
            self::markTestSkipped("Signals are not supported on Windows");
        }

        $process = new Process(self::CMD_PROCESS_SLOW);
        $process->start();
        $process->signal(0);
        self::assertSame(0, $process->join());
    }

    public function testGetWorkingDirectoryIsDefault()
    {
        $process = new Process(self::CMD_PROCESS);
        self::assertSame(\getcwd(), $process->getWorkingDirectory());
    }

    public function testGetWorkingDirectoryIsCustomized()
    {
        $process = new Process(self::CMD_PROCESS, __DIR__);
        self::assertSame(__DIR__, $process->getWorkingDirectory());
    }

    public function testGetEnv()
    {
        $process = new Process(self::CMD_PROCESS);
        self::assertSame([], $process->getEnv());
    }

    public function testGetStdin()
    {
        $process = new Process(self::CMD_PROCESS);
        $process->start();
        self::assertInstanceOf(ProcessOutputStream::class, $process->getStdin());
        $process->join();
    }

    public function testGetStdout()
    {
        $process = new Process(self::CMD_PROCESS);
        $process->start();
        self::assertInstanceOf(ProcessInputStream::class, $process->getStdout());
        $process->join();
    }

    public function testGetStderr()
    {
        $process = new Process(self::CMD_PROCESS);
        $process->start();
        self::assertInstanceOf(ProcessInputStream::class, $process->getStderr());
        $process->join();
    }

    public function testProcessEnvIsValid()
    {
        $process = new Process(self::CMD_PROCESS, null, [
            'test' => 'foobar',
            'PATH' => \getenv('PATH'),
            'SystemRoot' => \getenv('SystemRoot') ?: '', // required on Windows for process wrapper
        ]);
        $process->start();
        self::assertSame('foobar', $process->getEnv()['test']);
        $process->join();
    }

    public function testProcessEnvIsInvalid()
    {
        $this->expectException(\Error::class);

        $process = new Process(self::CMD_PROCESS, null, [
            ['error_value'],
        ]);
    }

    public function testGetStdinIsStatusError()
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process has not been started or has not completed starting');

        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStdin();
    }

    public function testGetStdoutIsStatusError()
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process has not been started or has not completed starting');

        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStdout();
    }

    public function testGetStderrIsStatusError()
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process has not been started or has not completed starting');

        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStderr();
    }

    public function testProcessCantBeCloned()
    {
        $this->expectException(\Error::class);

        $process = new Process(self::CMD_PROCESS);
        $clone = clone $process;
    }

    public function testKillImmediately()
    {
        $this->expectException(ProcessException::class);
        $this->expectExceptionMessage('The process was killed');

        $process = new Process(self::CMD_PROCESS_SLOW);
        $process->start();
        $process->kill();
        $process->join();
    }

    public function testKillThenReadStdout()
    {
        $this->expectException(ProcessException::class);
        $this->expectExceptionMessage('The process was killed');

        $process = new Process(self::CMD_PROCESS_SLOW);
        $process->start();

        delay(0.1); // Give process a chance to start, otherwise a different error is thrown.

        $process->kill();

        self::assertNull($process->getStdout()->read());

        $process->join();
    }

    public function testProcessHasNotBeenStartedWithJoin()
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process has not been started');

        $process = new Process(self::CMD_PROCESS);
        $process->join();
    }

    public function testProcessHasNotBeenStartedWithGetPid()
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process has not been started');

        $process = new Process(self::CMD_PROCESS);
        $process->getPid();
    }

    public function testProcessIsNotRunningWithKill()
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process is not running');

        $process = new Process(self::CMD_PROCESS);
        $process->kill();
    }

    public function testProcessIsNotRunningWithSignal()
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process is not running');

        $process = new Process(self::CMD_PROCESS);
        $process->signal(0);
    }

    public function testProcessHasBeenStarted()
    {
        $this->expectException(StatusError::class);
        $this->expectExceptionMessage('Process has not been started');

        $process = new Process(self::CMD_PROCESS);
        $process->join();
    }

    public function testCommand()
    {
        $process = new Process([self::CMD_PROCESS]);
        self::assertSame(\implode(" ", \array_map("escapeshellarg", [self::CMD_PROCESS])), $process->getCommand());
    }

    public function testOptions()
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
    public function testSpawnMultipleProcesses(int $count)
    {
        $processes = [];
        for ($i = 0; $i < $count; ++$i) {
            $command = \DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit $i" : "exit $i";
            $processes[] = new Process(self::CMD_PROCESS_SLOW . " && " . $command);
        }

        $promises = [];
        foreach ($processes as $process) {
            $process->start();
            $promises[] = coroutine(fn () => $process->join());
        }

        self::assertEquals(\range(0, $count - 1), Future\all($promises));
    }

    public function testReadOutputAfterExit()
    {
        $process = new Process(["php", __DIR__ . "/bin/worker.php"]);
        $process->start();

        $process->getStdin()->write("exit 2")->await();
        self::assertSame("..", $process->getStdout()->read());

        self::assertSame(0, $process->join());
    }

    public function testReadOutputAfterExitWithLongOutput()
    {
        $process = new Process(["php", __DIR__ . "/bin/worker.php"]);
        $process->start();

        $count = 128 * 1024 + 1;
        $process->getStdin()->write("exit " . $count)->await();
        self::assertSame(\str_repeat(".", $count), buffer($process->getStdout()));

        self::assertSame(0, $process->join());
    }

    public function testKillPHPImmediately()
    {
        $socket = \stream_socket_server("tcp://0.0.0.0:10000", $errno, $errstr);
        self::assertNotFalse($socket);
        $process = new Process(["php", __DIR__ . "/bin/socket-worker.php"]);
        $process->start();
        $conn = \stream_socket_accept($socket);
        self::assertSame('start', \fread($conn, 5));
        $process->kill();
        delay(0); // Tick event loop to send signal
        self::assertEmpty(\fread($conn, 3));
    }

    /**
     * @requires extension pcntl
     */
    public function testSignal()
    {
        $process = new Process(["php", __DIR__ . "/bin/signal-process.php"]);
        $process->start();
        delay(0.1); // Give process time to set up single handler.
        $process->signal(\SIGTERM);
        self::assertSame(42, $process->join());
    }

    public function testDebugInfo()
    {
        $process = new Process(["php", __DIR__ . "/bin/worker.php"], __DIR__);

        self::assertSame([
            'command' => IS_WINDOWS
                ? "\"php\" \"" . __DIR__ . "/bin/worker.php\""
                : "'php' '" . __DIR__ . "/bin/worker.php'",
            'cwd' => __DIR__,
            'env' => [],
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
