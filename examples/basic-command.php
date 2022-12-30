<?php declare(strict_types=1);

require dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Process\Process;

// "echo" is a shell internal command on Windows and doesn't work.
$command = DIRECTORY_SEPARATOR === "\\" ? "cmd /c echo Hello World!" : "echo 'Hello, world!'";

$process = Process::start($command);

echo ByteStream\buffer($process->getStdout());

$exitCode = $process->join();
echo "Process exited with {$exitCode}.\n";
