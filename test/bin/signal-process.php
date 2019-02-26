<?php

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

Amp\Loop::run(function () {
    Amp\Loop::unreference(Amp\Loop::onSignal(\SIGTERM, function () {
        exit(42);
    }));

    Amp\Loop::delay(1000, function () {
        exit(0);
    });
});
