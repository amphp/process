<?php

namespace Amp\Process\Internal\Posix;

use Amp\Process\Internal\ProcessHandle;

/** @internal */
final class PosixHandle extends ProcessHandle
{
    public ?string $extraDataPipeCallbackId = null;
}
