<?php

namespace Amp\Process\Internal;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;

/** @internal */
final class ProcessStreams
{
    public function __construct(
        public readonly WritableResourceStream $stdin,
        public readonly ReadableResourceStream $stdout,
        public readonly ReadableResourceStream $stderr,
    ) {
    }
}
