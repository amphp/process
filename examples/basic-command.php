<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\Message;
use Amp\Process\Process;

Amp\Loop::run(function () {
    $process = new Process("echo 'Hello, world!'");
    $process->start();

    echo yield new Message($process->getStdout());

    $code = yield $process->join();
    echo "Process exited with {$code}.\n";
});
