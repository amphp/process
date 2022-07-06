<?php

namespace Amp\Process\Internal;

/** @internal  */
final class ProcessContext
{
    public function __construct(
        public readonly ProcessHandle $handle,
        public readonly ProcessStreams $streams,
    ) {
    }
}
