<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Build\Runtime;

use Psr\Log\LoggerInterface;
use Symfony\Component\TuiApp\Build\BuildTarget;
use Symfony\Component\TuiApp\Build\ComposerExtensions;
use Symfony\Component\TuiApp\Config\AppConfig;

/**
 * The PHP installed on the computer: nothing is shipped; the extensions it must have are told.
 *
 * @internal
 */
final class SystemRuntime implements RuntimeInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function check(AppConfig $config): array
    {
        return [];
    }

    public function prepare(AppConfig $config, BuildTarget $target): void
    {
        $required = (new ComposerExtensions($this->logger))->required($config->getProjectDir(), $config->getPhpExtensions(), $config->getPhpVersion(), $target->name);
        if ($required) {
            $this->logger->info('[{target}] the PHP of the computer must have {extensions}', ['target' => $target->name, 'extensions' => ComposerExtensions::describe($required)]);
        }
    }
}
