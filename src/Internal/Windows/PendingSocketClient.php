<?php

namespace Amp\Process\Internal\Windows;

/**
 * @internal
 * @codeCoverageIgnore Windows only.
 */
final class PendingSocketClient
{
    public ?string $readWatcher = null;
    public ?string $timeoutWatcher = null;
    public string $receivedDataBuffer = '';
    public int $pid;
    public int $streamId;
}
