<?php

namespace Amp\Process\Internal;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\DeferredFuture;

abstract class ProcessHandle
{
    /** @var DeferredFuture<int> */
    public DeferredFuture $joinDeferred;

    public WritableResourceStream $stdin;

    public ReadableResourceStream $stdout;

    public ReadableResourceStream $stderr;

    public int $originalParentPid;

    public int $pid;

    public int $status = ProcessStatus::STARTING;

    public function __construct()
    {
        $this->joinDeferred = new DeferredFuture;
        $this->originalParentPid = \getmypid();
    }
}
