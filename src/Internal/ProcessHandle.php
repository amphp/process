<?php

namespace Amp\Process\Internal;

use Amp\DeferredFuture;

/** @internal */
abstract class ProcessHandle
{
    /** @var resource */
    private $proc;

    /** @var DeferredFuture<int> */
    public readonly DeferredFuture $joinDeferred;

    public readonly int $originalParentPid;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $pid;

    public int $status = ProcessStatus::STARTING;

    /**
     * @param resource $proc
     */
    public function __construct($proc)
    {
        $this->proc = $proc;
        $this->joinDeferred = new DeferredFuture;
        $this->originalParentPid = \getmypid();
    }
}
