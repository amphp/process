<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Process\StreamedProcess;

Amp\Loop::run(function() {
    $process = new StreamedProcess("echo 'Hello, world!'");
    $promise = $process->execute();

    echo yield $process->getStdout();

    $code = yield $promise;
    echo "Process exited with {$code}.\n";
});
