<?php

namespace Amp\Process\Internal\Windows;

use Amp\Struct;

/**
 * @internal
 * @codeCoverageIgnore Windows only.
 */
final class PendingSocketClient
{
    use Struct;

    public ?string $readWatcher = null;
    public ?string $timeoutWatcher = null;
    public string $receivedDataBuffer = '';
    public int $pid;
    public int $streamId;
}
