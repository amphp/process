<?php

namespace Amp\Process\Internal;

/** @internal */
final class ProcHolder
{
    private ProcessRunner $runner;

    private ProcessHandle $handle;

    public function __construct(ProcessRunner $runner, ProcessHandle $handle)
    {
        $this->runner = $runner;
        $this->handle = $handle;
    }

    public function __destruct()
    {
        $this->runner->destroy($this->handle);
    }
}
