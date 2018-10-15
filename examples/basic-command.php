<?php

include \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\Message;
use Amp\Process\Process;

Amp\Loop::run(function () {
    // "echo" is a shell internal command on Windows and doesn't work.
    $command = DIRECTORY_SEPARATOR === "\\" ? "cmd /c echo Hello World!" : "echo 'Hello, world!'";

    $process = new Process($command);
    $process->start();

    echo yield new Message($process->getStdout());

    $code = yield $process->join();
    echo "Process exited with {$code}.\n";
});
