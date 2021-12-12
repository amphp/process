<?php

namespace Amp\Process\Internal\Windows;

use Amp\ByteStream\ReadableResourceStream;
use Amp\Process\Internal\ProcessHandle;
use Amp\Sync\Barrier;

/**
 * @internal
 * @codeCoverageIgnore Windows only.
 */
final class WindowsHandle extends ProcessHandle
{
    public Barrier $startBarrier;

    public ReadableResourceStream $exitCodeStream;

    public int $wrapperPid;

    /** @var resource[] */
    public array $sockets = [];

    /** @var string[] */
    public array $securityTokens = [];

    public function __construct()
    {
        parent::__construct();

        $this->startBarrier = new Barrier(4);
    }
}
