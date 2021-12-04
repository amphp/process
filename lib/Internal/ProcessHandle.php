<?php

namespace Amp\Process\Internal;

use Amp\DeferredFuture;
use Amp\Process\ReadableProcessStream;
use Amp\Process\WritableProcessStream;

abstract class ProcessHandle
{
    public WritableProcessStream $stdin;

    public ReadableProcessStream $stdout;

    public ReadableProcessStream $stderr;

    /** @var DeferredFuture<int> */
    public DeferredFuture $pidDeferred;

    public int $status = ProcessStatus::STARTING;
}
