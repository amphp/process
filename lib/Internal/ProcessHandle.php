<?php

namespace Amp\Process\Internal;

use Amp\Deferred;
use Amp\Process\ProcessInputStream;
use Amp\Process\ProcessOutputStream;

abstract class ProcessHandle
{
    public ProcessOutputStream $stdin;

    public ProcessInputStream $stdout;

    public ProcessInputStream $stderr;

    /** @var Deferred<int> */
    public Deferred $pidDeferred;

    public int $status = ProcessStatus::STARTING;
}
