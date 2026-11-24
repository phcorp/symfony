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
use Symfony\Component\Filesystem\Path;

/**
 * The PHP extensions a project and the packages it installs require.
 *
 * @internal
 */
final class ComposerExtensions
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The extensions the project and the packages it installs require (ext-* in their composer.json, not their
     * require-dev nor the dev packages), but those configured, those PHP always has, and those the project or its
     * packages provide or replace. With the list of the extensions static-php-cli builds: those it does not build
     * on the target are left out, with a warning.
     *
     * @param list<string> $configured
     * @param string       $version    The PHP version
     * @param string|null  $staticList The static-extensions.list file of the hosts
     *
     * @return array<string, list<string>> The packages that require each extension, by extension
     */
    public function required(string $projectDir, array $configured, string $version, string $target, ?string $staticList = null): array
    {
        // phar: build-php always builds it; uri, lexbor and opcache: always in PHP 8.5
        $always = ['core', 'date', 'hash', 'json', 'pcre', 'random', 'reflection', 'spl', 'standard', 'phar'];
        if (version_compare($version, '8.5', '>=')) {
            array_push($always, 'uri', 'lexbor', 'opcache');
        }
        $configured = array_map(strtolower(...), $configured);
        $projectDir = Path::makeAbsolute($projectDir, getcwd() ?: '/');
        $root = json_decode((string) @file_get_contents($projectDir.'/composer.json'), true);
        $root = \is_array($root) ? $root : [];
        $packages = [['name' => \is_string($root['name'] ?? null) ? $root['name'] : 'the project'] + $root];
        $vendor = \is_string($root['config']['vendor-dir'] ?? null) ? $root['config']['vendor-dir'] : 'vendor';
        $file = Path::makeAbsolute($vendor, $projectDir).'/composer/installed.json';
        $installed = json_decode((string) @file_get_contents($file), true);
        if (!\is_array($installed)) {
            $this->logger->warning('[{target}] no {file}, the extensions the packages require are not looked for', ['target' => $target, 'file' => $file]);
            $installed = [];
        }
        $dev = (array) ($installed['dev-package-names'] ?? []);
        foreach ($installed['packages'] ?? $installed as $package) {
            if (\is_array($package) && !\in_array($package['name'] ?? null, $dev, true)) {
                $packages[] = $package;
            }
        }

        // What the project or a package provides itself, like a polyfill
        $provided = [];
        foreach ($packages as $package) {
            foreach ([...array_keys((array) ($package['provide'] ?? [])), ...array_keys((array) ($package['replace'] ?? []))] as $name) {
                if (\is_string($name) && null !== $extension = self::extension($name)) {
                    $provided[] = $extension;
                }
            }
        }

        $required = [];
        foreach ($packages as $package) {
            foreach (array_keys((array) ($package['require'] ?? [])) as $name) {
                if (\is_string($name) && null !== ($extension = self::extension($name)) && !\in_array($extension, $always, true) && !\in_array($extension, $configured, true) && !\in_array($extension, $provided, true)) {
                    $required[$extension][] = (string) $package['name'];
                }
            }
        }
        ksort($required);

        // What static-php-cli cannot build there: the application may still run, as long as it does not need it
        $os = explode('-', $target)[0];
        $available = null !== $staticList ? self::staticExtensions($staticList, $os) : null;
        foreach (null !== $available ? array_keys($required) : [] as $extension) {
            if (!\in_array($extension, $available, true)) {
                $this->logger->warning('[{target}] {extension}, required by {packages}, is not built on {os}', ['target' => $target, 'extension' => $extension, 'packages' => implode(', ', $required[$extension]), 'os' => $os]);
                unset($required[$extension]);
            }
        }

        return $required;
    }

    /**
     * The extensions static-php-cli builds on a platform, from the list of the hosts; null without the list.
     *
     * @return list<string>|null
     */
    public static function staticExtensions(string $list, string $os): ?array
    {
        if (!is_file($list)) {
            return null;
        }
        $extensions = [];
        foreach (file($list, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (!str_starts_with($line, '#') && 2 === \count($parts) && \in_array($os, explode(',', $parts[1]), true)) {
                $extensions[] = $parts[0];
            }
        }

        return $extensions;
    }

    /**
     * @param array<string, list<string>> $required
     */
    public static function describe(array $required): string
    {
        return implode('; ', array_map(static fn (string $extension, array $packages): string => \sprintf('%s, required by %s', $extension, implode(', ', $packages)), array_keys($required), $required));
    }

    private static function extension(string $name): ?string
    {
        if (!str_starts_with($name = strtolower($name), 'ext-')) {
            return null;
        }

        return 'zend-opcache' === substr($name, 4) ? 'opcache' : substr($name, 4);
    }
}
