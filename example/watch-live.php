<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\{ Listener, Process\StreamedProcess };

AsyncInterop\Loop::execute(\Amp\wrap(function() {
    $process = new StreamedProcess("echo 1; sleep 1; echo 2; sleep 1; echo 3");
    $process->start();

    $listener = new Listener($process->getStdOut());

    while (yield $listener->advance()) {
        echo $listener->getCurrent();
    }

    echo "Process exited with {$listener->getResult()}.\n";
}));
