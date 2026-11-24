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

/**
 * Records the commands a build runs instead of running them, and answers them.
 */
final class ProcessRunnerStub
{
    /** @var list<array{command: list<string>, cwd: string|null}> */
    public array $commands = [];

    /**
     * @param (\Closure(list<string> $command, ?string $cwd): array{0?: int, 1?: string, 2?: string})|null $answer The exit status, the output and the error output of a command
     */
    public function __construct(
        private readonly ?\Closure $answer = null,
    ) {
    }

    /**
     * @param list<string>                   $command
     * @param \Closure(string, string): void $callback
     */
    public function __invoke(array $command, ?string $cwd, ?float $timeout, \Closure $callback): int
    {
        $this->commands[] = ['command' => $command, 'cwd' => $cwd];
        [$status, $output, $errorOutput] = (null === $this->answer ? [] : ($this->answer)($command, $cwd)) + [0, '', ''];
        if ('' !== $errorOutput) {
            $callback('err', $errorOutput);
        }
        if ('' !== $output) {
            $callback('out', $output);
        }

        return $status;
    }

    /**
     * @return list<list<string>>
     */
    public function commands(): array
    {
        return array_column($this->commands, 'command');
    }
}
