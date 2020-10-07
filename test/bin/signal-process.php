<?php

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

Amp\Loop::unreference(Amp\Loop::onSignal(\SIGTERM, function (): void {
    exit(42);
}));

Amp\delay(1000);

exit(0);
