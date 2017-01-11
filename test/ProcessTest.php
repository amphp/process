<?php

namespace Amp\Test\Process;

use Amp\Process\Process;
use Amp\Process\ProcessException;
use AsyncInterop\Loop;

class ProcessTest extends \PHPUnit_Framework_TestCase {
    const CMD_PROCESS = 'echo foo';

    /**
     * @expectedException \Amp\Process\StatusError
     */
    public function testMultipleExecution() {
        Loop::execute(function() {
            $process = new Process(self::CMD_PROCESS);
            $process->start();
            $process->start();
        });
    }

    public function testJoinResolvesToExitCode() {
        Loop::execute(\Amp\wrap(function() {
            $process = new Process(self::CMD_PROCESS);
            $process->start();
            $promise = $process->join();

            $code = yield $promise;

            $this->assertSame(0, $code);
        }));
    }

    public function testCommandCanRun() {
        Loop::execute(function() {
            $process = new Process(self::CMD_PROCESS);
            $this->assertSame(0, $process->getPid());
            $process->start();

            $promise = $process->join();

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
            $process->start();
            $promise = $process->join();

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