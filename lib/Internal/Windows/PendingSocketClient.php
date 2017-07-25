<?php

namespace Amp\Process\Internal\Windows;

final class PendingSocketClient
{
    public $readWatcher;
    public $timeoutWatcher;
    public $recievedDataBuffer = '';
    public $pid;
    public $streamId;
}
