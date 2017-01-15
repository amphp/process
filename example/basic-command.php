<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Process\StreamedProcess;

AsyncInterop\Loop::execute(Amp\wrap(function() {
    $process = new StreamedProcess("echo 'Hello, world!'");
    $promise = $process->execute();

    echo yield $process->getStdout();

    $code = yield $promise;
    echo "Process exited with {$code}.\n";
}));
