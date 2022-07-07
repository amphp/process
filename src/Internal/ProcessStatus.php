<?php

namespace Amp\Process\Internal;

/** @internal */
enum ProcessStatus
{
    case Starting;
    case Running;
    case Ended;
}
