<?php
declare(strict_types=1);

namespace Amp\Process\Test;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Process;

/**
 * @requires extension pcntl
 */
class SignalNameTest extends AsyncTestCase
{
    public function provideSignals(): \Generator
    {
        yield 'SIGHUP' => [\SIGHUP, 'SIGHUP'];
        yield 'SIGINT' => [\SIGINT, 'SIGINT'];
        yield 'SIGQUIT' => [\SIGQUIT, 'SIGQUIT'];
        yield 'SIGILL' => [\SIGILL, 'SIGILL'];
        yield 'SIGABRT' => [\SIGABRT, 'SIGABRT'];
        yield 'SIGFPE' => [\SIGFPE, 'SIGFPE'];
        yield 'SIGKILL' => [\SIGKILL, 'SIGKILL'];
        yield 'SIGUSR1' => [\SIGUSR1, 'SIGUSR1'];
        yield 'SIGUSR2' => [\SIGUSR2, 'SIGUSR2'];
        yield 'SIGSEGV' => [\SIGSEGV, 'SIGSEGV'];
        yield 'SIGPIPE' => [\SIGPIPE, 'SIGPIPE'];
        yield 'SIGALRM' => [\SIGALRM, 'SIGALRM'];
        yield 'SIGTERM' => [\SIGTERM, 'SIGTERM'];
        yield 'SIGCHLD' => [\SIGCHLD, 'SIGCHLD'];
        yield 'SIGCONT' => [\SIGCONT, 'SIGCONT'];
        yield 'SIGSTOP' => [\SIGSTOP, 'SIGSTOP'];
        yield 'SIGTSTP' => [\SIGTSTP, 'SIGTSTP'];
        yield 'SIGTTIN' => [\SIGTTIN, 'SIGTTIN'];
        yield 'SIGTTOU' => [\SIGTTOU, 'SIGTTOU'];
        yield 'SIGIOT' => [\SIGIOT, 'SIGABRT']; // Alias for SIGABRT.
    }

    /**
     * @dataProvider provideSignals
     */
    public function testGetSignalName(int $signalNumber, string $expectedName): void
    {
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('This test requires the pcntl extension.');
        }

        self::assertSame($expectedName, Process\getSignalName($signalNumber));
    }
}
