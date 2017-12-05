<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Process\Process;

Amp\Loop::run(function () {
    // abuse ping for sleep, see https://stackoverflow.com/a/1672349/2373138
    $command = \DIRECTORY_SEPARATOR === "\\"
        ? "cmd /c echo 1 & ping -n 2 127.0.0.1 > nul & echo 2 & ping -n 2 127.0.0.1 > nul & echo 3 & exit 42"
        : "echo 1; sleep 1; echo 2; sleep 1; echo 3; exit 42";
    $process = new Process($command);
    $process->start();

    $stream = $process->getStdout();

    while (null !== $chunk = yield $stream->read()) {
        echo $chunk;
    }

    $code = yield $process->join();
    echo "Process exited with {$code}.\n";
});
