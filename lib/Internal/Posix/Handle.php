<?php

namespace Amp\Process\Internal\Posix;

use Amp\Deferred;
use Amp\Process\Internal\ProcessHandle;

final class Handle extends ProcessHandle
{
    public function __construct() {
        $this->startDeferred = new Deferred;
        $this->endDeferred = new Deferred;
        $this->originalParentPid = \getmypid();
    }

    /** @var Deferred */
    public $endDeferred;

    /** @var Deferred */
    public $startDeferred;

    /** @var resource */
    public $proc;

    /** @var resource[] */
    public $pipes;

    /** @var string */
    public $extraDataPipeWatcher;

    /** @var int */
    public $originalParentPid;
}
