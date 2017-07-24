<?php declare(strict_types=1);

namespace Amp\Process\Internal;

use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;

abstract class ProcessHandle
{
    /** @var ResourceOutputStream */
    public $stdin;

    /** @var ResourceInputStream */
    public $stdout;

    /** @var ResourceInputStream */
    public $stderr;

    /** @var int */
    public $pid = 0;

    /** @var bool */
    public $status = ProcessStatus::STARTING;
}
