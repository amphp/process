<?php

namespace Amp\Process\Internal\Windows;

use Amp\Deferred;
use Amp\Process\Internal\ProcessHandle;

/**
 * @internal
 * @codeCoverageIgnore Windows only.
 */
final class Handle extends ProcessHandle
{
    public function __construct()
    {
        $this->joinDeferred = new Deferred;
        $this->pidDeferred = new Deferred;
    }

    public Deferred $joinDeferred;

    public ?string $exitCodeWatcher = null;

    public bool $exitCodeRequested = false;

    /** @var resource */
    public $proc;

    public int $wrapperPid;

    /** @var resource */
    public $wrapperStderrPipe;

    /** @var resource[] */
    public array $sockets = [];

    /** @var Deferred[] */
    public array $stdioDeferreds = [];

    public ?string $childPidWatcher = null;

    public ?string $connectTimeoutWatcher = null;

    public array $securityTokens = [];
}
