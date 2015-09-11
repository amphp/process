<?php

namespace Amp\Process\Test;

use Amp\LibeventReactor;

class LibeventReactorProcessTest extends AbstractProcessTest {

    public function setUp(){
        \Amp\reactor(new LibeventReactor());
    }

    public function testReactor() {
        $this->assertInstanceOf('\Amp\LibeventReactor', \Amp\reactor());
    }
}