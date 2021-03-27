<?php

namespace Amp\Process\Internal;

use Amp\Deferred;
use Amp\Process\ProcessInputStream;
use Amp\Process\ProcessOutputStream;
use Revolt\EventLoop\Internal\Struct;

abstract class ProcessHandle
{
    use Struct;

    public ProcessOutputStream $stdin;

    public ProcessInputStream $stdout;

    public ProcessInputStream $stderr;

    public Deferred $pidDeferred;

    public int $status = ProcessStatus::STARTING;
}
