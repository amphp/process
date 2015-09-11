<?php

namespace Amp\Process\Test;


use Amp\NativeReactor;

class NativeReactorProcessTest extends AbstractProcessTest {

    public function setUp(){
        \Amp\reactor(new NativeReactor());
    }

    public function testReactor() {
        $this->assertInstanceOf('\Amp\NativeReactor', \Amp\reactor());
    }
}