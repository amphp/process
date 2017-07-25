<?php

namespace Amp\Process\Internal\Windows;

use Amp\Deferred;
use Amp\Process\Internal\ProcessHandle;

final class Handle extends ProcessHandle
{
    public function __construct() {
        $this->startDeferred = new Deferred;
        $this->endDeferred = new Deferred;
    }

    /** @var Deferred */
    public $startDeferred;

    /** @var Deferred */
    public $endDeferred;

    /** @var string */
    public $exitCodeWatcher;

    /** @var resource */
    public $proc;

    /** @var int */
    public $wrapperPid;

    /** @var resource */
    public $wrapperStderrPipe;

    /** @var resource[] */
    public $sockets;

    /** @var string */
    public $connectTimeoutWatcher;

    /** @var string[] */
    public $securityTokens;
}
