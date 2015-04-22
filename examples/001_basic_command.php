<?php

use Amp\Process;

include __DIR__."/../vendor/autoload.php";

\Amp\run(function() {
	$proc = new Process("echo 1");
	$result = (yield $proc->exec(Process::BUFFER_ALL));

	var_dump($result->stdout); // "1"
});
