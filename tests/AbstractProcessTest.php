<?php

namespace Amp\Process\Test;

use Amp\Process;

/**
 * Class AbstractProcessTest
 * @package Amp\Process\Test
 * @covers \Amp\Process
 */
abstract class AbstractProcessTest extends \PHPUnit_Framework_TestCase {

    const CMD_PROCESS = 'echo 1;';

    abstract function testReactor();

    /**
     * @coverage \Amp\Process::exec
     * @expectedException \RuntimeException
     */
    public function testMultipleExecution() {
        \Amp\run(function() {
            $process = new Process(self::CMD_PROCESS);
            $process->exec();
            $process->exec();
        });
    }

    /**
     * @coverage \Amp\Process::exec
     * @coverage \Amp\Process::pid
     * @coverage \Amp\Process::status
     */
    public function testCommandCanRun() {
        \Amp\run(function(){
            $process = new Process(self::CMD_PROCESS);
            $this->assertNull($process->status());
            $promise = $process->exec();

            $this->assertArrayHasKey('running', $process->status());
            $this->assertArrayHasKey('pid', $process->status());
            $this->assertTrue($process->status()['running']);
            $this->assertInternalType('int', $process->pid());
        });
    }

    /**
     * @coverage \Amp\Process::exec
     */
    public function testProcessResolvePromise()
    {
        \Amp\run(function(){
            $process = new Process(self::CMD_PROCESS);
            $promise = $process->exec();
            $this->assertInstanceOf('\Amp\Promise', $promise);
            $return = (yield $promise);
            $this->assertObjectHasAttribute('exit', $return);
            $this->assertInternalType('int', $return->exit);
        });
    }

    /**
     * @coverage \Amp\Process::kill
     */
    public function testKillSignals()
    {
        \Amp\run(function(){
            $process = new Process(self::CMD_PROCESS);
            $promise = $process->exec();
            $process->kill();
            $return = (yield $promise);
            $this->assertObjectHasAttribute('signal', $return);
            $this->assertObjectHasAttribute('exit', $return);
            $this->assertInternalType('int', $return->signal);
            $this->assertInternalType('int', $return->exit);
            $this->assertEquals(15, $return->signal);
            $this->assertEquals(-1, $return->exit);
        });
    }


    /**
     * @covers \Amp\Process::getCommand
     */
    public function testGetCommand() {
        $process = new Process(self::CMD_PROCESS);
        $this->assertSame(self::CMD_PROCESS, $process->getCommand());
    }

}