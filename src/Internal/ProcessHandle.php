<?php

namespace Amp\Process\Internal;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;

abstract class ProcessHandle
{
    public WritableResourceStream $stdin;

    public ReadableResourceStream $stdout;

    public ReadableResourceStream $stderr;

    public int $pid;

    public int $status = ProcessStatus::STARTING;
}
