<?php declare(strict_types=1);

include dirname(__DIR__) . "/vendor/autoload.php";

use Amp\Future;
use Amp\Process\Process;
use function Amp\async;
use function Amp\ByteStream\getStdout;

function show_process_output(Process $process): void
{
    Amp\ByteStream\pipe($process->getStdout(), getStdout());

    $code = $process->join();
    $pid = $process->getPid();

    getStdout()->write("Process {$pid} exited with {$code}\n");
}

$futures = [];
foreach (['8.8.8.8', '8.8.4.4', 'google.com', 'stackoverflow.com', 'github.com'] as $host) {
    $command = \DIRECTORY_SEPARATOR === "\\"
        ? "ping -n 5 {$host}"
        : "ping -c 5 {$host}";
    $futures[] = async(fn () => show_process_output(Process::start($command)));
}

Future\await($futures);
