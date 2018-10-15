<?php

include \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Process\Process;

Amp\Loop::run(function () {
    // "echo" is a shell internal command on Windows and doesn't work.
    $command = DIRECTORY_SEPARATOR === "\\" ? "cmd /c echo Hello World!" : "echo 'Hello, world!'";

    $process = new Process($command);
    yield $process->start();

    echo yield ByteStream\buffer($process->getStdout());

    $code = yield $process->join();
    echo "Process exited with {$code}.\n";
});
