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
use Symfony\Component\TuiApp\Build\CommandRunner;
use Symfony\Component\TuiApp\Build\ComposerExtensions;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Config\Configuration;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;
use Symfony\Component\TuiApp\Exception\RuntimeException;

/**
 * The embedded PHP library linked into a mobile host.
 *
 * @internal
 */
final class MobileRuntime
{
    private const EXTENSIONS = ['ctype', 'filter', 'iconv', 'mbstring', 'tokenizer', 'phar', 'dom', 'libxml', 'core', 'date', 'hash', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard', 'uri', 'lexbor', 'opcache'];

    public function __construct(
        private readonly CommandRunner $runner,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function prepare(AppConfig $config, BuildTarget $target): void
    {
        if (!\in_array($config->getPhpVersion(), ['8.5', '8.5.13'], true)) {
            throw new InvalidArgumentException('The mobile runtime currently builds PHP 8.5.13; set "runtime.php.version" to "8.5" or "8.5.13".');
        }
        $extensions = array_map(strtolower(...), $config->getPhpExtensions());
        if (Configuration::DEFAULT_EXTENSIONS === $extensions) {
            $extensions = array_diff($extensions, ['pcntl', 'posix']);
        }
        $required = (new ComposerExtensions($this->logger))->required($config->getProjectDir(), [...self::EXTENSIONS, ...$extensions], $config->getPhpVersion(), $target->name);
        if ($unsupported = array_diff([...$extensions, ...array_keys($required)], self::EXTENSIONS)) {
            throw new InvalidArgumentException(\sprintf('The mobile runtime does not build these PHP extensions: %s. Supported extensions: %s.', implode(', ', array_unique($unsupported)), implode(', ', self::EXTENSIONS)));
        }
        $directory = $target->payloadDir.'/runtime';
        $this->runner->run([$target->hostsDir.'/mobile/build-runtime.sh', $target->name, $directory], null, null);
        if (!is_file($directory.'/build/libs/libphp.a')) {
            throw new RuntimeException(\sprintf('The mobile runtime build did not write "%s/build/libs/libphp.a".', $directory));
        }
    }
}
