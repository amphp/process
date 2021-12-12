<?php

namespace Amp\Process\Internal;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\DeferredFuture;

abstract class ProcessHandle
{
    /** @var resource */
    public $proc;

    /** @var DeferredFuture<int> */
    public DeferredFuture $joinDeferred;

    public int $originalParentPid;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public WritableResourceStream $stdin;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ReadableResourceStream $stdout;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ReadableResourceStream $stderr;

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
