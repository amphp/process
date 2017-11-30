<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Process\Process;

Amp\Loop::run(function () {
    $process = new Process("echo 1; sleep 1; echo 2; sleep 1; echo 3; exit 42");
    $process->start();

    $stream = $process->getStdout();

    while (null !== $chunk = yield $stream->read()) {
        echo $chunk;
    }

    $code = yield $process->join();
    echo "Process exited with {$code}.\n";
});
