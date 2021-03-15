<?php
$socket = \fsockopen('127.0.0.1', 10000);
\fwrite($socket, 'start');
$result = \sleep(2);
\fwrite($socket, 'end');
