<?php declare(strict_types=1);
$socket = fsockopen('127.0.0.1', 10000);
fwrite($socket, 'start');
sleep(2);
fwrite($socket, 'end');
