<?php

namespace Amp\Process\Internal;

/** @internal */
final class ProcHolder
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly ProcessHandle $handle
    ) {
    }

    public function __destruct()
    {
        $this->runner->destroy($this->handle);
    }
}
