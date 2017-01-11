<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\{ Listener, Process\StreamedProcess };

AsyncInterop\Loop::execute(Amp\wrap(function() {
    $process = new StreamedProcess("echo 1");
    $process->execute();

    $listener = new Listener($process->getStdOut());

    while (yield $listener->advance()) {
        echo $listener->getCurrent();
    }

    echo "Process exited with {$listener->getResult()}.\n";
}));
