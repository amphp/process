<?php

namespace Amp\Process\Test;

use Amp\UvReactor;

class UvReactorProcessTest extends AbstractProcessTest {

    public function setUp(){
        if (extension_loaded("uv")) {
            \Amp\reactor($assign = new UvReactor);
        } else {
            $this->markTestSkipped(
                "php-uv extension not loaded"
            );
        }
    }

    public function testReactor() {
        $this->assertInstanceOf('\Amp\UvReactor', \Amp\reactor());
    }
}