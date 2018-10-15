<?php

$content = \fread(STDIN, 1024);

$command = \explode(" ", $content);

if (\count($command) !== 2) {
    exit(1);
}

if ($command[0] === "exit") {
    echo \str_repeat(".", (int) $command[1]);
    exit;
}

exit(1);
