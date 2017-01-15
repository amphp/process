<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Process\StreamedProcess;

AsyncInterop\Loop::execute(\Amp\wrap(function() {
    $process = new StreamedProcess('read ; echo "$REPLY"');
    $promise = $process->execute();

    /* send to stdin */
    $process->write("abc\n");

    echo yield $process->getStdout();

    $code = yield $promise;
    echo "Process exited with {$code}.\n";
}));
