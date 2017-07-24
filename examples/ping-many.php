<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Process\Process;
use Amp\Promise;
use function Amp\Promise\all;

function show_process_output(Promise $promise): \Generator
{
    /** @var Process $process */
    $process = yield $promise;

//    $process->getStdin()->close();

    $stream = $process->getStdout();
    while ($chunk = yield $stream->read()) {
        echo $chunk;
    }

    $code = yield $process->join();

    echo "Process {$process->getPid()} exited with {$code}\n";
}

Amp\Loop::run(function () {
    $hosts = ['8.8.8.8', '8.8.4.4', 'google.com', 'stackoverflow.com', 'github.com'];

    $promises = [];

    foreach ($hosts as $host) {
        $promises[] = new \Amp\Coroutine(show_process_output(Process::start("ping {$host}")));
    }

    yield all($promises);
});
