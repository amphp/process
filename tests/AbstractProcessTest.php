<?php

namespace Amp\Process\Test;

use Amp\Process;

abstract class AbstractProcessTest extends \PHPUnit_Framework_TestCase {
    const CMD_PROCESS = 'echo foo';

    abstract function testReactor();

    /**
     * @expectedException \RuntimeException
     */
    public function testMultipleExecution() {
        \Amp\run(function() {
            $process = new Process(self::CMD_PROCESS);
            $process->exec();
            $process->exec();
        });
    }

    public function testCommandCanRun() {
        \Amp\run(function() {
            $process = new Process(self::CMD_PROCESS);
            $this->assertNull($process->pid());
            $promise = $process->exec();

            $completed = false;
            $promise->when(function() use (&$completed) { $completed = true; });
            $this->assertFalse($completed);
            $this->assertInternalType('int', $process->pid());
        });
    }

    public function testProcessResolvePromise() {
        \Amp\run(function() {
            $process = new Process(self::CMD_PROCESS);
            $promise = $process->exec(Process::BUFFER_ALL);

            $this->assertInstanceOf('\Amp\Promise', $promise);
            $return = (yield $promise);

            $this->assertObjectHasAttribute('exit', $return);
            $this->assertInternalType('int', $return->exit);
            $this->assertObjectHasAttribute('stdout', $return);
            $this->assertSame("foo\n", $return->stdout);
            $this->assertObjectHasAttribute('stderr', $return);
            $this->assertSame("", $return->stderr);
        });
    }

    public function testKillSignals() {
        \Amp\run(function() {
            $process = new Process(self::CMD_PROCESS);
            $promise = $process->exec();

            $process->kill();
            $return = (yield $promise);

            $this->assertObjectHasAttribute('signal', $return);
            $this->assertObjectHasAttribute('exit', $return);
            $this->assertSame(15, $return->signal);
            $this->assertSame(-1, $return->exit);
        });
    }

    public function testWatch() {
        \Amp\run(function() {
            $process = new Process(self::CMD_PROCESS);
            $this->assertNull($process->pid());
            $promise = $process->exec();

            $msg = "";
            $promise->watch(function($update) use (&$msg) {
                list($type, $partMsg) = $update;
                $this->assertSame("out", $type);
                $msg .= $partMsg;
            });

            yield $promise;
            $this->assertSame("foo\n", $msg);
        });

    }

    public function testCommand() {
        $process = new Process(self::CMD_PROCESS);
        $this->assertSame(self::CMD_PROCESS, $process->command());
    }

    public function testOptions() {
        $process = new Process(self::CMD_PROCESS);
        $this->assertSame([], $process->options());
    }
}