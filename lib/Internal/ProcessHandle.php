<?php

namespace Amp\Process\Internal;

use Amp\DeferredFuture;
use Amp\Process\ProcessReadableStream;
use Amp\Process\ProcessWritableStream;

abstract class ProcessHandle
{
    public ProcessWritableStream $stdin;

    public ProcessReadableStream $stdout;

    public ProcessReadableStream $stderr;

    /** @var DeferredFuture<int> */
    public DeferredFuture $pidDeferred;

    public int $status = ProcessStatus::STARTING;
}
