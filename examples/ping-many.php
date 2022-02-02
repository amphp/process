<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Future;
use Amp\Process\Process;
use function Amp\async;

function show_process_output(Process $process): void
{
    $stream = $process->getStdout();

    while (null !== $chunk = $stream->read()) {
        echo $chunk;
    }

    $code = $process->join();
    $pid = $process->getPid();

    echo "Process {$pid} exited with {$code}\n";
}

$hosts = ['8.8.8.8', '8.8.4.4', 'google.com', 'stackoverflow.com', 'github.com'];

$futures = [];

foreach ($hosts as $host) {
    $command = \DIRECTORY_SEPARATOR === "\\"
        ? "ping -n 5 {$host}"
        : "ping -c 5 {$host}";
    $futures[] = async(fn () => show_process_output(Process::start($command)));
}

Future\await($futures);
