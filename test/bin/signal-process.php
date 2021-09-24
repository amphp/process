<?php

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

Revolt\EventLoop\Loop::unreference(Revolt\EventLoop\Loop::onSignal(\SIGTERM, function (): void {
    exit(42);
}));

Amp\delay(1);

exit(0);
