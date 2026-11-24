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

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Gives each message as a line: "[target] warning: ..." for a warning, indented for debug.
 */
final class LogRecorder extends AbstractLogger
{
    /**
     * @param \Closure(string): void $record
     */
    public function __construct(
        private readonly \Closure $record,
    ) {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $message = strtr((string) $message, array_combine(array_map(static fn (string $key): string => '{'.$key.'}', array_keys($context)), array_map(strval(...), $context)) ?: []);
        $message = match ($level) {
            LogLevel::DEBUG => '  '.$message,
            LogLevel::INFO, LogLevel::NOTICE => $message,
            default => preg_replace('/^(\[[^\]]+\] )?/', '$1warning: ', $message, 1),
        };

        ($this->record)($message);
    }
}
