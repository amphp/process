<?php

namespace Amp\Process\Test;

use Amp\Loop;
use Amp\Process\Process;
use Amp\Process\ProcessInputStream;
use Amp\Process\ProcessOutputStream;
use PHPUnit\Framework\TestCase;

class ProcessTest extends TestCase {
    const CMD_PROCESS = \DIRECTORY_SEPARATOR === "\\" ? "cmd /c echo foo" : "echo foo";

    /**
     * @expectedException \Amp\Process\StatusError
     */
    public function testMultipleExecution() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $process->start();
            $process->start();
        });
    }

    public function testIsRunning() {
        Loop::run(function () {
            $process = new Process(\DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit 42" : "exit 42");
            $process->start();
            $promise = $process->join();

            $this->assertTrue($process->isRunning());

            yield $promise;

            $this->assertFalse($process->isRunning());
        });
    }

    public function testExecuteResolvesToExitCode() {
        Loop::run(function () {
            $process = new Process(\DIRECTORY_SEPARATOR === "\\" ? "cmd /c exit 42" : "exit 42");
            $process->start();

            $code = yield $process->join();

            $this->assertSame(42, $code);
            $this->assertFalse($process->isRunning());
        });
    }

    public function testCommandCanRun() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $process->start();
            $promise = $process->join();

            $this->assertInternalType('int', yield $process->getPid());
        });
    }

    public function testProcessCanTerminate() {
        if (\DIRECTORY_SEPARATOR === "\\") {
            $this->markTestSkipped("Signals are not supported on Windows");
        }

        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $process->start();
            $promise = $process->join();

            $process->signal(0);
            $this->assertInternalType('object', $process->getPid());
        });
    }

    public function testGetWorkingDirectoryIsDefault() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $this->assertSame(getcwd(), $process->getWorkingDirectory());
        });
    }

    public function testGetWorkingDirectoryIsCustomized() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS, __DIR__);
            $this->assertSame(__DIR__, $process->getWorkingDirectory());
        });
    }

    public function testGetEnv() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $this->assertSame([], $process->getEnv());
        });
    }

    public function testGetStdin() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $process->start();
            $this->assertInstanceOf(ProcessOutputStream::class, $process->getStdin());
            yield $process->join();
        });
    }

    public function testGetStdout() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $process->start();
            $this->assertInstanceOf(ProcessInputStream::class, $process->getStdout());
            yield $process->join();
        });
    }

    public function testGetStderr() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $process->start();
            $this->assertInstanceOf(ProcessInputStream::class, $process->getStderr());
            yield $process->join();
        });
    }

    public function testProcessEnvIsValid() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS, null, [
                'env_name' => 'env_value'
            ]);
            $process->start();
            $this->assertSame('env_value', $process->getEnv()['env_name']);
            yield $process->join();
        });
    }

    /**
     * @expectedException \Error
     */
    public function testProcessEnvIsInvalid() {
        $process = new Process(self::CMD_PROCESS, null, [
            ['error_value']
        ]);
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testGetStdinIsStatusError() {
        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStdin();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testGetStdoutIsStatusError() {
        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStdout();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testGetStderrIsStatusError() {
        $process = new Process(self::CMD_PROCESS, null, []);
        $process->getStderr();
    }

    public function testProcessCanReset() {
        $this->expectException(\Error::class);
        $process = new Process(self::CMD_PROCESS);
        $process->start();
        $promise = $process->join();
        $processReset = clone $process;
        $processReset->getPid();
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cloning is not allowed!
     */
    public function testProcessResetDeferredIsNull() {
        $process = new Process(self::CMD_PROCESS);
        $process->start();
        $promise = $process->join();
        $processReset = clone $process;
        $processReset->join();
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Cloning is not allowed!
     */
    public function testProcessResetSignalIsNotRunning() {
        $process = new Process(self::CMD_PROCESS);
        $process->start();
        $promise = $process->join();
        $processReset = clone $process;
        $processReset->signal(1);
    }

    /**
     * @expectedException \Amp\Process\ProcessException
     * @expectedExceptionMessage The process was killed
     */
    public function testKillSignals() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $process->start();
            $promise = $process->join();

            $process->kill();

            yield $promise;
        });
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testProcessHasNotBeenStartedWithJoin() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $promise = $process->join();

            yield $promise;
        });
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testProcessHasNotBeenStartedWithGetPid() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $promise = $process->getPid();

            yield $promise;
        });
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process is not running.
     */
    public function testProcessIsNotRunningWithKill() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);

            $process->kill();

            yield $promise;
        });
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process is not running.
     */
    public function testProcessIsNotRunningWithSignal() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);

            $process->signal(0);

            yield $promise;
        });
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage Process has not been started.
     */
    public function testProcessHasBeenStarted() {
        Loop::run(function () {
            $process = new Process(self::CMD_PROCESS);
            $promise = $process->join();

            yield $promise;
        });
    }

    public function testCommand() {
        $process = new Process([self::CMD_PROCESS]);
        $this->assertSame(\implode(" ", \array_map("escapeshellarg", [self::CMD_PROCESS])), $process->getCommand());
    }

    public function testOptions() {
        $process = new Process(self::CMD_PROCESS);
        $this->assertSame([], $process->getOptions());
    }
}
