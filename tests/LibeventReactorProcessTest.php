<?php

namespace Amp\Process\Test;

use Amp\LibeventReactor;

class LibeventReactorProcessTest extends AbstractProcessTest {
    protected function setUp() {
        if (extension_loaded("libevent")) {
            \Amp\reactor($assign = new LibeventReactor);
        } else {
            $this->markTestSkipped(
                "libevent extension not loaded"
            );
        }
    }

    public function testReactor() {
        $this->assertInstanceOf('\Amp\LibeventReactor', \Amp\reactor());
    }
}