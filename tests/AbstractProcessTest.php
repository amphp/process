<?php

namespace Amp\Process\Test;

use Amp\Process;

abstract class AbstractProcessTest extends \PHPUnit_Framework_TestCase {

    const CMD_PROCESS = "php -r 'sleep(2);'";

    abstract function testReactor();

    public function testGetCommand() {
        $process = new Process(self::CMD_PROCESS);
        $this->assertSame(self::CMD_PROCESS, $process->getCommand());
    }

    public function testCommandCanRun() {
        $process = new Process(self::CMD_PROCESS);
        $this->assertNull($process->status()['running']);
        $process->exec();
        $this->assertTrue($process->status()['running']);

        return $process;
    }

    /**
     * @depends testCommandCanRun
     */
    public function testPid(Process $process) {
        $this->assertInternalType('int', $process->pid());
    }
}