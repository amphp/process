<?php

namespace Amp\Process\Internal\Windows;

/**
 * @internal
 * @codeCoverageIgnore Windows only.
 */
final class HandshakeStatus
{
    public const SUCCESS = 0;
    public const SIGNAL_UNEXPECTED = 0x01;
    public const INVALID_STREAM_ID = 0x02;
    public const INVALID_PROCESS_ID = 0x03;
    public const DUPLICATE_STREAM_ID = 0x04;
    public const INVALID_CLIENT_TOKEN = 0x05;

    private function __construct()
    {
        // empty to prevent instances of this class
    }
}
