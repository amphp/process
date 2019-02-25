<?php

namespace Amp\Process;

const BIN_DIR = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'bin';
const IS_WINDOWS = (PHP_OS & "\xDF\xDF\xDF") === 'WIN';

if (IS_WINDOWS) {
    function escape_arg($arg)
    {
        return '"' . \preg_replace_callback('(\\\\*("|$))', function ($m) {
            return \str_repeat('\\', \strlen($m[0])) . $m[0];
        }, $arg) . '"';
    }
} else {
    function escape_arg($arg)
    {
        return \escapeshellarg($arg);
    }
}
