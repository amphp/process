<?php declare(strict_types=1);

use Amp\Process\Process;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// A child that fills its stderr pipe, which nobody reads, then gets killed.
$process = Process::start([\PHP_BINARY, '-n', '-r', 'fwrite(STDERR, str_repeat("x", 1 << 20)); sleep(10);']);

Amp\delay(0.5);

$process->kill();

echo "killed";
