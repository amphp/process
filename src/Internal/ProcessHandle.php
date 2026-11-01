<?php declare(strict_types=1);

namespace Amp\Process\Internal;

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/** @internal */
abstract class ProcessHandle
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var DeferredFuture<int> */
    public readonly DeferredFuture $joinDeferred;

    public readonly int $originalParentPid;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var positive-int
     */
    public int $pid;

    public ProcessStatus $status = ProcessStatus::Starting;

    /**
     * @param resource $proc
     */
    public function __construct(protected readonly mixed $proc)
    {
        $this->joinDeferred = new DeferredFuture();

        /** @psalm-suppress PossiblyFalsePropertyAssignmentValue */
        $this->originalParentPid = \getmypid();
    }

    abstract public function wait(): void;
}
