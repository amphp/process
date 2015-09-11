<?php

namespace Amp\Process\Test;

use Amp\UvReactor;

class UvReactorProcessTest extends AbstractProcessTest {

    public function setUp(){
        \Amp\reactor(new UvReactor());
    }

    public function testReactor() {
        $this->assertInstanceOf('\Amp\UvReactor', \Amp\reactor());
    }
}