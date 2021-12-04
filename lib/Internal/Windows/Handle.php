<?php

namespace Amp\Process\Internal\Windows;

use Amp\DeferredFuture;
use Amp\Process\Internal\ProcessHandle;

/**
 * @internal
 * @codeCoverageIgnore Windows only.
 */
final class Handle extends ProcessHandle
{
    public DeferredFuture $joinDeferred;
    public ?string $exitCodeWatcher = null;
    public bool $exitCodeRequested = false;
    /** @var resource */
    public $proc;
    public int $wrapperPid;
    /** @var resource */
    public $wrapperStderrPipe;
    /** @var resource[] */
    public array $sockets = [];
    /** @var DeferredFuture[] */
    public array $stdioDeferreds = [];
    public ?string $childPidWatcher = null;
    public ?string $connectTimeoutWatcher = null;
    public array $securityTokens = [];

    public function __construct()
    {
        $this->joinDeferred = new DeferredFuture;
        $this->pidDeferred = new DeferredFuture;
    }
}
