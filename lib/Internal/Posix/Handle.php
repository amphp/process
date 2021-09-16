<?php

namespace Amp\Process\Internal\Posix;

use Amp\Deferred;
use Amp\Process\Internal\ProcessHandle;

/** @internal */
final class Handle extends ProcessHandle
{
    /** @var Deferred<int> */
    public Deferred $joinDeferred;

    /** @var resource */
    public $proc;

    /** @var resource */
    public $extraDataPipe;

    public ?string $extraDataPipeWatcher = null;

    public ?string $extraDataPipeStartWatcher = null;

    public int $originalParentPid;

    public function __construct()
    {
        $this->pidDeferred = new Deferred;
        $this->joinDeferred = new Deferred;
        $this->originalParentPid = \getmypid();
    }
}
