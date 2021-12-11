<?php

namespace Amp\Process\Internal\Posix;

use Amp\DeferredFuture;
use Amp\Process\Internal\ProcessHandle;

/** @internal */
final class Handle extends ProcessHandle
{
    /** @var DeferredFuture<int> */
    public DeferredFuture $joinDeferred;

    /** @var resource */
    public $proc;

    /** @var resource */
    public $extraDataPipe;

    public ?string $extraDataPipeWatcher = null;

    public ?string $extraDataPipeStartWatcher = null;

    public int $originalParentPid;

    public function __construct()
    {
        $this->joinDeferred = new DeferredFuture;
        $this->originalParentPid = \getmypid();
    }
}
