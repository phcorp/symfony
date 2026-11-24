<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Build;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Symfony\Component\TuiApp\Exception\RuntimeException;

/**
 * Runs the commands of a build: what they print on stderr is logged as debug.
 *
 * The process runner runs a command and returns its exit status; it gives what
 * the command prints to its callback as it comes, with "out" or "err".
 *
 * @internal
 */
final class CommandRunner
{
    /** @var \Closure(list<string>, ?string, ?float, \Closure(string, string): void): int */
    private \Closure $processRunner;

    /**
     * @param (\Closure(list<string> $command, ?string $cwd, ?float $timeout, \Closure(string $type, string $data): void $callback): int)|null $processRunner
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        ?\Closure $processRunner = null,
    ) {
        $this->processRunner = $processRunner ?? self::runProcess(...);
    }

    /**
     * @param list<string>                  $command
     * @param (\Closure(string): void)|null $onOutput Called with each line of the output, as it is written
     *
     * @return string The output
     *
     * @throws RuntimeException When the command fails
     */
    public function run(array $command, ?string $cwd = null, ?float $timeout = 600, ?\Closure $onOutput = null): string
    {
        [$status, $output, $errorOutput] = $this->call($command, $cwd, $timeout, $onOutput);
        if (0 !== $status) {
            throw new RuntimeException(\sprintf("The command \"%s\" failed with status %d:\n%s", (new Process($command))->getCommandLine(), $status, trim($errorOutput ?: $output)));
        }

        return $output;
    }

    /**
     * @param list<string>                  $command
     * @param (\Closure(string): void)|null $onOutput Called with each line of the output, as it is written
     *
     * @return array{int, string, string} The exit status, the output and the error output
     */
    public function call(array $command, ?string $cwd = null, ?float $timeout = 600, ?\Closure $onOutput = null): array
    {
        $output = $errorOutput = $pending = '';
        $status = ($this->processRunner)($command, $cwd, $timeout, function (string $type, string $data) use ($onOutput, &$output, &$errorOutput, &$pending): void {
            if (Process::ERR === $type) {
                $errorOutput .= $data;
                foreach (explode("\n", rtrim($data)) as $line) {
                    $this->logger->debug($line);
                }

                return;
            }
            $output .= $data;
            if (null !== $onOutput) {
                // Whole lines: the output may come in pieces of any size
                $lines = explode("\n", $pending.$data);
                $pending = array_pop($lines);
                foreach ($lines as $line) {
                    $onOutput($line);
                }
            }
        });
        if (null !== $onOutput && '' !== $pending) {
            $onOutput($pending);
        }

        return [$status, $output, $errorOutput];
    }

    /**
     * @param list<string>                   $command
     * @param \Closure(string, string): void $callback
     */
    private static function runProcess(array $command, ?string $cwd, ?float $timeout, \Closure $callback): int
    {
        $process = new Process($command, $cwd, timeout: $timeout);
        try {
            return $process->run($callback);
        } catch (ProcessSignaledException $e) {
            throw new RuntimeException(\sprintf('The command "%s" was killed by signal %d. A PHP binary copied out of its installation must not need its libraries: give a static build.', $process->getCommandLine(), $e->getSignal()), 0, $e);
        }
    }
}
