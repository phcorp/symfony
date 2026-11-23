<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Build;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\TuiApp\Build\CommandRunner;
use Symfony\Component\TuiApp\Exception\RuntimeException;

class CommandRunnerTest extends TestCase
{
    public function testOutputComesInWholeLines()
    {
        $log = $lines = [];
        $runner = new CommandRunner(new LogRecorder(static function (string $message) use (&$log): void { $log[] = $message; }), static function (array $command, ?string $cwd, ?float $timeout, \Closure $callback): int {
            $callback('out', 'fir');
            $callback('err', "a warning\n");
            $callback('out', "st\nsecond\nla");
            $callback('out', 'st');

            return 0;
        });

        $output = $runner->run(['build'], onOutput: static function (string $line) use (&$lines): void { $lines[] = $line; });

        $this->assertSame("first\nsecond\nlast", $output);
        $this->assertSame(['first', 'second', 'last'], $lines);
        $this->assertSame(['  a warning'], $log, 'What a command prints on stderr is logged as debug');
    }

    public function testAFailureSaysWhatTheCommandPrinted()
    {
        $runner = new CommandRunner(new LogRecorder(static function (): void {}), (new ProcessRunnerStub(static fn (): array => [3, 'out', "no space left\n"]))(...));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(\sprintf("The command \"%s\" failed with status 3:\nno space left", (new Process(['build', '--out', 'My Notes.app']))->getCommandLine()));

        $runner->run(['build', '--out', 'My Notes.app']);
    }

    public function testCallReturnsTheStatusAndBothOutputs()
    {
        $runner = new CommandRunner(new LogRecorder(static function (): void {}), (new ProcessRunnerStub(static fn (): array => [1, 'out', 'err']))(...));

        $this->assertSame([1, 'out', 'err'], $runner->call(['elephc']));
    }

    public function testTheDefaultRunnerRunsAProcess()
    {
        $runner = new CommandRunner(new LogRecorder(static function (): void {}));

        $this->assertSame('8', $runner->run([\PHP_BINARY, '-r', 'echo PHP_MAJOR_VERSION;']));
    }
}
