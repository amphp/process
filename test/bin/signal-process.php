<?php declare(strict_types=1);

use Revolt\EventLoop;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

EventLoop::unreference(EventLoop::onSignal(\SIGTERM, fn () => exit(42)));

// The loop installs the handler when it next ticks.
Amp\delay(0);

echo "ready";

Amp\delay(1);

exit(0);
