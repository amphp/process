<?php

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

Revolt\EventLoop\Loop::unreference(Revolt\EventLoop\Loop::onSignal(\SIGTERM, function (): void {
    exit(42);
}));

Revolt\EventLoop\delay(1000);

exit(0);
