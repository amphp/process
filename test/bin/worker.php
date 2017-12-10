<?php

$content = fread(STDIN, 1024);

if  ($content === "exit") {
    echo "ok";
    exit;
}

exit(1);
