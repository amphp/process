<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\Message;
use Amp\Process\StreamedProcess;

Amp\Loop::run(function() {
    $process = new StreamedProcess('read ; echo "$REPLY"');
    $process->start();

    /* send to stdin */
    $process->write("abc\n");

    echo yield new Message($process);

    $code = yield $process->join();
    echo "Process exited with {$code}.\n";
});
