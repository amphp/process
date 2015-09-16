<?php

use Amp\Process;

include __DIR__."/../vendor/autoload.php";

\Amp\run(function() {
    $proc = new Process("echo 1; sleep 1; echo 2; sleep 1; echo 3");
    $promise = $proc->exec();

    $promise->watch(function($data) {
    	// $data[0] is either "out" or "err", $data[1] the actual data
    	list($type, $msg) = $data;
    	// "1" ... 2 seconds ... "2" ... 2 seconds ... "3"
    	print "$type: $msg";
    });

    $result = (yield $promise);

    // we aren't buffering by default (Process::BUFFER_NONE is default) ... so only exit code present and eventually the killing signal
    var_dump($result);
});