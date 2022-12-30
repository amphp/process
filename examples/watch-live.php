<?php declare(strict_types=1);

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Process\Process;

// abuse ping for sleep, see https://stackoverflow.com/a/1672349/2373138
$command = \DIRECTORY_SEPARATOR === "\\"
    ? "cmd /c echo 1 & ping -n 2 127.0.0.1 > nul & echo 2 & ping -n 2 127.0.0.1 > nul & echo 3 & exit 42"
    : "echo 1; sleep 1; echo 2; sleep 1; echo 3; exit 42";
$process = Process::start($command);

$stream = $process->getStdout();

while (null !== $chunk = $stream->read()) {
    echo $chunk;
}

$code = $process->join();
echo "Process exited with {$code}.\n";
