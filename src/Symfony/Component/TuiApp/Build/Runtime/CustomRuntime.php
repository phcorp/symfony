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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\TuiApp\Build\BuildTarget;
use Symfony\Component\TuiApp\Build\CommandRunner;
use Symfony\Component\TuiApp\Build\ComposerExtensions;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;
use Symfony\Component\TuiApp\Exception\RuntimeException;

/**
 * A PHP binary given by the application, shipped with it once checked.
 *
 * @internal
 */
final class CustomRuntime implements RuntimeInterface
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly LoggerInterface $logger,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function check(AppConfig $config): array
    {
        if (!is_file($binary = $config->path((string) $config->getPhpPath()))) {
            throw new InvalidArgumentException(\sprintf('The PHP binary "%s" of the custom runtime does not exist.', $binary));
        }

        return [];
    }

    public function prepare(AppConfig $config, BuildTarget $target): void
    {
        $extensions = $config->getPhpExtensions();
        $required = (new ComposerExtensions($this->logger))->required($config->getProjectDir(), $extensions, $config->getPhpVersion(), $target->name);
        if ($required) {
            $this->logger->info('[{target}] adding {extensions}', ['target' => $target->name, 'extensions' => ComposerExtensions::describe($required)]);
            $extensions = [...$extensions, ...array_keys($required)];
        }

        $php = $target->payloadDir.'/runtime/'.('windows' === $target->os ? 'php.exe' : 'php');
        $this->filesystem->copy($config->path((string) $config->getPhpPath()), $php, true);
        $this->filesystem->chmod($php, 0o755);

        if ($target->isCurrent() || $target->prefix) {
            $this->checkPhp([...$target->prefix, $php], $config->getPhpVersion(), $extensions, $required);
        } else {
            $this->logger->warning('the PHP binary of the {target} target cannot be checked on this computer', ['target' => $target->name]);
        }
    }

    /**
     * @param list<string>                $php        The command that runs the PHP binary
     * @param list<string>                $extensions
     * @param array<string, list<string>> $required   The packages that require each extension
     */
    private function checkPhp(array $php, string $minVersion, array $extensions, array $required): void
    {
        $output = $this->runner->run([...$php, '-n', '-r', 'echo PHP_VERSION, "\n", implode(",", array_map("strtolower", get_loaded_extensions()));']);
        [$version, $loaded] = explode("\n", trim($output), 2) + [1 => ''];

        if (version_compare($version, $minVersion, '<')) {
            throw new RuntimeException(\sprintf('The PHP binary is %s; the application needs %s or later ("runtime.php.min_version").', $version, $minVersion));
        }
        if ($missing = array_diff(array_map(strtolower(...), $extensions), explode(',', $loaded))) {
            $from = array_map(static fn (string $extension): string => isset($required[$extension]) ? \sprintf('%s, required by %s', $extension, implode(', ', $required[$extension])) : \sprintf('%s ("runtime.php.extensions")', $extension), array_values($missing));
            throw new RuntimeException(\sprintf('The PHP binary lacks the extension%s %s.', 1 === \count($missing) ? '' : 's', implode('; ', $from)));
        }
    }
}
