<?php declare(strict_types=1);

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Process\Process;

if (DIRECTORY_SEPARATOR === "\\") {
    echo "This example doesn't work on Windows." . PHP_EOL;
    exit(1);
}

$process = Process::start('read REPLY; echo "$REPLY"');

/* send to stdin */
$process->getStdin()->write("abc\n");

echo ByteStream\buffer($process->getStdout());

$code = $process->join();
echo "Process exited with {$code}.\n";
