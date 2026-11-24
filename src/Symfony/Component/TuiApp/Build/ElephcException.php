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

use Symfony\Component\TuiApp\Exception\RuntimeException;

/**
 * elephc could not compile the application: the problems it reported, by file.
 *
 * The elephc runtime is a beta. elephc compiles a subset of PHP, and code
 * written for the PHP interpreter, including Tui and its dependencies, uses
 * more than that subset today.
 *
 * @experimental
 */
final class ElephcException extends RuntimeException
{
    private const SHOWN = 30;
    private const SHOWN_OUTPUT = 2000;

    /**
     * @param list<array{file: string, line: int|null, message: string}> $problems
     */
    public function __construct(
        private readonly array $problems,
        private readonly string $output,
    ) {
        if (!$problems) {
            $shown = trim($output);
            if (\strlen($shown) > self::SHOWN_OUTPUT) {
                $shown = (preg_match('/^.{0,'.self::SHOWN_OUTPUT.'}/su', $shown, $match) ? $match[0] : substr($shown, 0, self::SHOWN_OUTPUT))."\n[...]";
            }

            parent::__construct(\sprintf("elephc failed (the elephc runtime is a beta):\n%s\nUse the embedded runtime, or change the code elephc does not support.", $shown));

            return;
        }

        $lines = [];
        foreach (\array_slice($problems, 0, self::SHOWN) as $problem) {
            $lines[] = \sprintf(' * %s%s: %s', $problem['file'], null === $problem['line'] ? '' : ':'.$problem['line'], $problem['message']);
        }
        if (\count($problems) > self::SHOWN) {
            $lines[] = \sprintf(' * and %d more', \count($problems) - self::SHOWN);
        }

        parent::__construct(\sprintf("elephc cannot compile the application (the elephc runtime is a beta); it reported %d problem%s:\n%s\nUse the embedded runtime, or change the code elephc does not support.", \count($problems), 1 === \count($problems) ? '' : 's', implode("\n", $lines)));
    }

    /**
     * Reads the errors elephc printed, like "error[/app/src/A.php:12:5]: Unknown type: Foo"
     * or "error: Circular interface inheritance...". Warnings are left out.
     */
    public static function fromOutput(string $output, string $baseDir): self
    {
        $problems = [];
        $seen = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim(preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line);
            if (preg_match('{^error\[(?<file>.+?):(?<line>\d+)(?::\d+)?\]:\s*(?<message>.+)$}', $line, $match)) {
                $file = str_starts_with($match['file'], $baseDir.'/') ? substr($match['file'], \strlen($baseDir) + 1) : $match['file'];
                $problem = ['file' => $file, 'line' => (int) $match['line'], 'message' => $match['message']];
            } elseif (preg_match('{^error:\s*(?<message>.+)$}', $line, $match)) {
                $problem = ['file' => '(application)', 'line' => null, 'message' => $match['message']];
            } else {
                continue;
            }

            $key = implode(':', $problem);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $problems[] = $problem;
            }
        }

        return new self($problems, $output);
    }

    /**
     * @return list<array{file: string, line: int|null, message: string}>
     */
    public function getProblems(): array
    {
        return $this->problems;
    }

    public function getOutput(): string
    {
        return $this->output;
    }
}
