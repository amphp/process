<?php declare(strict_types=1);

namespace Amp\Process\Internal;

use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;

abstract class ProcessHandle
{
    const STATUS_STARTING = 0;
    const STATUS_RUNNING = 1;
    const STATUS_ENDED = 2;

    /** @var ResourceOutputStream */
    public $stdin;

    /** @var ResourceInputStream */
    public $stdout;

    /** @var ResourceInputStream */
    public $stderr;

    /** @var int */
    public $pid = 0;

    /** @var bool */
    public $status = self::STATUS_STARTING;
}
