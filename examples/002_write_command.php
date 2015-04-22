<?php

use Amp\Process;

include __DIR__."/../vendor/autoload.php";

\Amp\run(function() {
	$proc = new Process('read ; echo "$REPLY"');
	$promise = $proc->exec(Process::BUFFER_ALL);

	/* send to stdin */
	$proc->write("abc\n");

	/* wait for process end */
	$result = (yield $promise);

	var_dump($result->stdout); // "abc"
});
