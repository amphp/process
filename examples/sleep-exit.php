<?php

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Process\Process;

$process = Process::start('sleep 10');

// This will kill the child process
unset($process);
