<?php

namespace Amp\Process\Internal\Windows;

use Amp\Struct;

final class PendingSocketClient {
    use Struct;

    public $readWatcher;
    public $timeoutWatcher;
    public $receivedDataBuffer = '';
    public $pid;
    public $streamId;
}
