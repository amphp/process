<?php

namespace Amp\Process\Internal;

/** @internal */
final class ProcessStatus
{
    public const STARTING = 0;
    public const RUNNING = 1;
    public const ENDED = 2;

    private function __construct()
    {
        // empty to prevent instances of this class
    }
}
