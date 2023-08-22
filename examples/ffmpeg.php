<?php

use Amp\Pipeline\Pipeline;
use Amp\Process\Process;
use function Amp\ByteStream\getStdout;

require dirname(__DIR__) . "/vendor/autoload.php";

$ffmpeg = getenv('FFMPEG_BIN') ?: 'ffmpeg';
$concurrency = $argv[1] ?? 3;
$start = microtime(true);

Pipeline::fromIterable(new DirectoryIterator('.'))
    ->concurrent(3)
    ->filter(fn ($item) => $item->getExtension() === 'mkv')
    ->map(fn ($item) => createVideoClip($ffmpeg, $item->getPathname(), getTempDestination()))
    ->forEach(fn ($result) => getStdout()->write('Successfully created clip from ' . $result[0] . ' => ' . $result[1] . PHP_EOL));

$end = microtime(true);
echo 'Directory processed in ' . round($end - $start, 1) . ' seconds' . PHP_EOL;

function getTempDestination(): string
{
    $destination = tempnam(sys_get_temp_dir(), 'video');
    unlink($destination);
    $dir = dirname($destination);
    $file = basename($destination, '.tmp');

    return $dir . DIRECTORY_SEPARATOR . $file . '.mp4';
}

function createVideoClip(string $ffmpeg, string $source, string $destination): array
{
    $cmd = sprintf('%s -threads 1 -i %s -t 30 -crf 26 -c:v h264 -c:a ac3 %s', $ffmpeg, $source, $destination);

    $success = Process::start($cmd)->join() === 0;

    if ($success) {
        return [$source, $destination];
    } else {
        throw new \RuntimeException('Unable to perform conversion');
    }
}