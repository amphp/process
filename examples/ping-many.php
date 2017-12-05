<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Process\Process;
use function Amp\Promise\all;

function show_process_output(Process $process): \Generator {
    $stream = $process->getStdout();

    while (null !== $chunk = yield $stream->read()) {
        echo $chunk;
    }

    $code = yield $process->join();
    $pid = yield $process->getPid();

    echo "Process {$pid} exited with {$code}\n";
}

Amp\Loop::run(function () {
    $hosts = ['8.8.8.8', '8.8.4.4', 'google.com', 'stackoverflow.com', 'github.com'];

    $promises = [];

    foreach ($hosts as $host) {
        $command = \DIRECTORY_SEPARATOR === "\\"
            ? "ping -n 5 {$host}"
            : "ping -c 5 {$host}";
        $process = new Process($command);
        $process->start();
        $promises[] = new Amp\Coroutine(show_process_output($process));
    }

    yield all($promises);
});
