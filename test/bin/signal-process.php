<?php declare(strict_types=1);

use Revolt\EventLoop;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

EventLoop::unreference(EventLoop::onSignal(\SIGTERM, fn () => exit(42)));

Amp\delay(1);

exit(0);
