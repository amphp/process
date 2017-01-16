<?php

namespace Amp\Test\Process;

use Amp\Process\Process;
use AsyncInterop\Loop;

class ProcessTest extends \PHPUnit_Framework_TestCase {
    const CMD_PROCESS = 'echo foo';

    /**
     * @expectedException \Amp\Process\StatusError
     */
    public function testMultipleExecution() {
        Loop::execute(function() {
            $process = new Process(self::CMD_PROCESS);
            $process->execute();
            $process->execute();
        });
    }

    public function testIsRunning() {
        Loop::execute(\Amp\wrap(function() {
            $process = new Process("exit 42");
            $promise = $process->execute();

            $this->assertTrue($process->isRunning());

            yield $promise;

            $this->assertFalse($process->isRunning());
        }));
    }


    public function testExecuteResolvesToExitCode() {
        Loop::execute(\Amp\wrap(function() {
            $process = new Process("exit 42");
            $code = yield $process->execute();

            $this->assertSame(42, $code);
            $this->assertFalse($process->isRunning());
        }));
    }

    public function testCommandCanRun() {
        Loop::execute(function() {
            $process = new Process(self::CMD_PROCESS);
            $promise = $process->execute();

            $completed = false;
            $promise->when(function() use (&$completed) { $completed = true; });
            $this->assertFalse($completed);
            $this->assertInternalType('int', $process->getPid());
        });
    }

    /**
     * @expectedException \Amp\Process\ProcessException
     * @expectedExceptionMessage The process was killed
     */
    public function testKillSignals() {
        Loop::execute(\Amp\wrap(function() {
            $process = new Process(self::CMD_PROCESS);
            $promise = $process->execute();

            $process->kill();

            $code = yield $promise;
        }));
    }

    public function testCommand() {
        $process = new Process(self::CMD_PROCESS);
        $this->assertSame(self::CMD_PROCESS, $process->getCommand());
    }

    public function testOptions() {
        $process = new Process(self::CMD_PROCESS);
        $this->assertSame([], $process->getOptions());
    }
}