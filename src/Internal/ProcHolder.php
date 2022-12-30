<?php declare(strict_types=1);

namespace Amp\Process\Internal;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/** @internal */
final class ProcHolder
{
    use ForbidCloning;
    use ForbidSerialization;

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
