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

    public function testIsRunning(): void
    {
        $process = Process::start(\DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit 42" : "exit 42");
        $future = async(fn () => $process->join());

        self::assertTrue($process->isRunning());

        $future->await();

        self::assertFalse($process->isRunning());
    }

    public function testExecuteResolvesToExitCode(): void
    {
        $process = Process::start(\DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit 42" : "exit 42");

        $code = $process->join();

        self::assertSame(42, $code);
        self::assertFalse($process->isRunning());
    }

    public function testCommandCanRun(): void
    {
        $process = Process::start(self::CMD_PROCESS);

        self::assertSame(0, $process->join());
    }

    public function testProcessCanTerminate(): void
    {
        if (\DIRECTORY_SEPARATOR === "\\") {
            self::markTestSkipped("Signals are not supported on Windows");
        }

        $process = Process::start(self::CMD_PROCESS_SLOW);
        $process->signal(0);
        self::assertSame(0, $process->join());
    }

    public function testGetWorkingDirectoryIsDefault(): void
    {
        $process = Process::start(self::CMD_PROCESS);
        self::assertNull($process->getWorkingDirectory());
        $process->join();
    }

    public function testGetWorkingDirectoryIsCustomized(): void
    {
        $process = Process::start(self::CMD_PROCESS, __DIR__);
        self::assertSame(__DIR__, $process->getWorkingDirectory());
        $process->join();
    }

    public function testGetEnv(): void
    {
        $process = Process::start(self::CMD_PROCESS);
        self::assertSame([], $process->getEnvironment());
        $process->join();
    }

    public function testGetStdin(): void
    {
        $process = Process::start(self::CMD_PROCESS_STDIN);

        $process->getStdin()->write('exit 5');
        $process->getStdin()->end();

        self::assertSame('.....', buffer($process->getStdout()));

        $process->join();
    }

    public function testGetStdout(): void
    {
        $process = Process::start(self::CMD_PROCESS);

        self::assertSame('foo' . \PHP_EOL, buffer($process->getStdout()));

        $process->join();
    }

    public function testGetStderr(): void
    {
        $process = Process::start(self::CMD_PROCESS_STDERR);

        self::assertSame('foo' . \PHP_EOL, buffer($process->getStderr()));

        $process->join();
    }

    public function testProcessEnvIsValid(): void
    {
        $process = Process::start(self::CMD_PROCESS, null, [
            'test' => 'foobar',
            'PATH' => \getenv('PATH'),
            'SystemRoot' => \getenv('SystemRoot') ?: '', // required on Windows for process wrapper
        ]);

        self::assertSame('foobar', $process->getEnvironment()['test']);

        $process->join();
    }

    public function testProcessEnvIsInvalid(): void
    {
        $this->expectException(\Error::class);

        /** @noinspection PhpParamsInspection */
        Process::start(self::CMD_PROCESS, null, [
            ['error_value'],
        ]);
    }

    public function testProcessCantBeCloned(): void
    {
        $process = Process::start(self::CMD_PROCESS);

        $this->expectException(\Error::class);

        try {
            /** @noinspection PhpExpressionResultUnusedInspection */
            clone $process;
        } finally {
            $process->join();
        }
    }

    public function testKillImmediately(): void
    {
        $this->setTimeout(1);

        $process = Process::start(self::CMD_PROCESS_SLOW);
        $process->kill();

        self::assertSame(IS_WINDOWS ? 1 : 137, $process->join());
    }

    public function testKillThenReadStdout(): void
    {
        $this->setTimeout(1);

        $process = Process::start(self::CMD_PROCESS_SLOW);
        $process->kill();

        self::assertNull($process->getStdout()->read());
        self::assertSame(IS_WINDOWS ? 1 : 137, $process->join());
    }

    public function testCommand(): void
    {
        $process = Process::start([self::CMD_PROCESS]);
        self::assertSame(\implode(" ", \array_map("escapeshellarg", [self::CMD_PROCESS])), $process->getCommand());
        $process->join();
    }

    public function testOptions(): void
    {
        $process = Process::start(self::CMD_PROCESS);
        self::assertSame([], $process->getOptions());
        $process->join();
    }

    public function getProcessCounts(): array
    {
        return \array_map(static function (int $count): array {
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
            $processes[] = Process::start(self::CMD_PROCESS_SLOW . " && " . $command);
        }

        $promises = [];
        foreach ($processes as $process) {
            $promises[] = async(fn () => $process->join());
        }

        self::assertEquals(\range(0, $count - 1), Future\all($promises));
    }

    public function testReadOutputAfterExit(): void
    {
        $process = Process::start(["php", __DIR__ . "/bin/worker.php"]);

        $process->getStdin()->write("exit 2");
        self::assertSame("..", $process->getStdout()->read());

        self::assertSame(0, $process->join());
    }

    public function testReadOutputAfterExitWithLongOutput(): void
    {
        $process = Process::start(["php", __DIR__ . "/bin/worker.php"]);

        $count = 128 * 1024 + 1;
        $process->getStdin()->write("exit " . $count);
        self::assertSame(\str_repeat(".", $count), buffer($process->getStdout()));

        self::assertSame(0, $process->join());
    }

    public function testKillPHPImmediately(): void
    {
        $socket = \stream_socket_server("tcp://127.0.0.1:10000");
        self::assertNotFalse($socket);
        $process = Process::start(["php", __DIR__ . "/bin/socket-worker.php"]);
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
        $process = Process::start(["php", __DIR__ . "/bin/signal-process.php"]);
        delay(0.1); // Give process time to set up single handler.
        $process->signal(\SIGTERM);

        self::assertSame(42, $process->join());
    }

    public function testDebugInfo(): void
    {
        $process = Process::start(["php", __DIR__ . "/bin/worker.php"], __DIR__);

        $debugInfo = $process->__debugInfo();

        self::assertIsInt($debugInfo['pid']);
        unset($debugInfo['pid']);

        self::assertSame([
            'command' => IS_WINDOWS
                ? "\"php\" \"" . __DIR__ . "/bin/worker.php\""
                : "'php' '" . __DIR__ . "/bin/worker.php'",
            'workingDirectory' => __DIR__,
            'environment' => [],
            'options' => [],
            'status' => 'running',
        ], $debugInfo);
    }
}
