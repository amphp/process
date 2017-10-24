<?php

namespace Amp\Test\Process;

use Amp\Loop;
use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Process\Process;
use PHPUnit\Framework\TestCase;

class ProcessTest extends TestCase {
    const CMD_PROCESS = 'echo foo';

    protected function setUp() {
        file_exists(__DIR__.'/../stream') ? null:touch(__DIR__.'/../stream');
    }

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
            $process = new Process("exit 42");
            $process->start();
            $promise = $process->join();

            $this->assertTrue($process->isRunning());

            yield $promise;

            $this->assertFalse($process->isRunning());
        });
    }


    public function testExecuteResolvesToExitCode() {
        Loop::run(function () {
            $process = new Process("exit 42");
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

            $completed = false;
            $promise->onResolve(function () use (&$completed) { $completed = true; });
            $this->assertFalse($completed);
            $this->assertInternalType('int', $process->getPid());
        });
    }

    public function testCommandArray() {
        Loop::run(function () {
            $process = new Process([self::CMD_PROCESS]);
            $process->start();
            $promise = $process->join();

            $completed = false;
            $promise->onResolve(function () use (&$completed) { $completed = true; });
            $this->assertFalse($completed);
            $this->assertInternalType('int', $process->getPid());
        });
    }

    public function testProcessCanTerminate() {
        Loop::run(function () {
            $process = new Process([self::CMD_PROCESS]);
            $process->start();
            $promise = $process->join();

            $completed = false;
            $promise->onResolve(function () use (&$completed) { $completed = true; });
            $process->signal(0);
            $this->assertInternalType('int', $process->getPid());
        });
    }

    public function testGetWorkingDirectoryIsDefault() {
        Loop::run(function () {
            $process = new Process([self::CMD_PROCESS]);
            $this->assertSame(getcwd(), $process->getWorkingDirectory());
        });
    }

    public function testGetWorkingDirectoryIsCustomized() {
        Loop::run(function () {
            $process = new Process([self::CMD_PROCESS], __DIR__);
            $this->assertSame(__DIR__, $process->getWorkingDirectory());
        });
    }

    public function testGetEnv() {
        Loop::run(function () {
            $process = new Process([self::CMD_PROCESS], null, []);
            $this->assertCount(0, $process->getEnv());
        });
    }

    public function testGetStdinIsCustomized() {
        Loop::run(function () {
            $process = new Process([self::CMD_PROCESS], null, [], [
                new ResourceInputStream(fopen(__DIR__.'/../stream', 'r')),
            ]);
            $process->start();
            $promise = $process->join();
            $completed = false;
            $promise->onResolve(function () use (&$completed) { $completed = true; });
            $this->assertInstanceOf(ResourceOutputStream::class, $process->getStdin());
        });
    }

    public function testGetStdoutIsCustomized() {
        Loop::run(function () {
            $process = new Process([self::CMD_PROCESS], null, [], [
                new ResourceOutputStream(fopen(__DIR__.'/../stream', 'w')),
            ]);
            $process->start();
            $promise = $process->join();
            $completed = false;
            $promise->onResolve(function () use (&$completed) { $completed = true; });
            $this->assertInstanceOf(ResourceInputStream::class, $process->getStdout());
        });
    }

    public function testGetStderrIsCustomized() {
        Loop::run(function () {
            $process = new Process([self::CMD_PROCESS], null, [], [
                new ResourceOutputStream(fopen(__DIR__.'/../stream', 'w')),
            ]);
            $process->start();
            $promise = $process->join();
            $completed = false;
            $promise->onResolve(function () use (&$completed) { $completed = true; });
            $this->assertInstanceOf(ResourceInputStream::class, $process->getStderr());
        });
    }

    public function testProcessEnvIsValid() {
        $process = new Process([self::CMD_PROCESS], null, [
            'env_value'
        ]);
        $process->start();
        $promise = $process->join();
        $completed = false;
        $promise->onResolve(function () use (&$completed) { $completed = true; });
        $this->assertSame('env_value', $process->getEnv()[0]);
    }

    public function testProcessDeferredIsFailed() {
        $process = new Process(new \Exception());
        $this->assertNull($process->start());
    }

    /**
     * @expectedException \Error
     */
    public function testProcessEnvIsInvalid() {
        $process = new Process([self::CMD_PROCESS], null, [
            ['error_value']
        ]);
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage The process has not been started
     */
    public function testGetStdinIsNull() {
        $process = new Process([self::CMD_PROCESS], null, []);
        $process->getStdin();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage The process has not been started
     */
    public function testGetStdoutIsNull() {
        $process = new Process([self::CMD_PROCESS], null, []);
        $process->getStdout();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage The process has not been started
     */
    public function testGetStderrIsNull() {
        $process = new Process([self::CMD_PROCESS], null, []);
        $process->getStderr();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage The process has not been started
     */
    public function testProcessCanReset() {
        $process = new Process(self::CMD_PROCESS);
        $process->start();
        $promise = $process->join();
        $completed = false;
        $promise->onResolve(function () use (&$completed) { $completed = true; });
        $processReset = clone $process;
        $processReset->getPid();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage The process is not running
     */
    public function testProcessResetDeferredIsNull() {
        $process = new Process(self::CMD_PROCESS);
        $process->start();
        $promise = $process->join();
        $completed = false;
        $promise->onResolve(function () use (&$completed) { $completed = true; });
        $processReset = clone $process;
        $processReset->join();
    }

    /**
     * @expectedException \Amp\Process\StatusError
     * @expectedExceptionMessage The process is not running
     */
    public function testProcessResetSignalIsNotRunning() {
        $process = new Process(self::CMD_PROCESS);
        $process->start();
        $promise = $process->join();
        $completed = false;
        $promise->onResolve(function () use (&$completed) { $completed = true; });
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

            $code = yield $promise;
        });
    }

    public function testCommand() {
        $process = new Process(self::CMD_PROCESS);
        $this->assertSame(self::CMD_PROCESS, $process->getCommand());
    }

    public function testOptions() {
        $process = new Process(self::CMD_PROCESS);
        $this->assertSame([], $process->getOptions());
    }

    public function tearDown() {
        @unlink(__DIR__.'/../stream');
    }
}
