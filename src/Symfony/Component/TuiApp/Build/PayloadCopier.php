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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;

/**
 * Copies what an application ships into the payload of its packages.
 *
 * Links are followed when they stay in the project, like the ones of Composer path repositories.
 * On Windows those are junctions, which PHP sees as neither a link nor a folder
 * (https://github.com/php/php-src/issues/9674): a path is told to be reached through one
 * when its real path is not the one of its folder followed by its name. Paths are compared
 * with "/" separators, the ones excludes are written with.
 *
 * Files that may hold secrets never ship: the local .env files and Composer's auth.json, even
 * when "build.include" lists them. Their PHP dumps, written by "composer dump-env", ship only when
 * "build.include" lists their exact path. Either way, a warning says so. A key that decrypts the
 * secrets of the application stops the copy, listed or not.
 *
 * @internal
 */
final class PayloadCopier
{
    /**
     * Files that may hold secrets and never ship: the local .env files and the credentials of Composer.
     */
    public const NEVER_SHIPPED = ['.env.local', '.env.*.local', 'auth.json'];

    /**
     * The PHP dumps of the local .env files, which ship when "build.include" lists them.
     */
    public const ENV_DUMPS = ['.env.local.php', '.env.*.local.php'];

    /** @var list<string> */
    private array $exclude = [];
    /** @var list<string> */
    private array $include = [];
    /** @var array<string, true> The paths already looked at, relative to the project */
    private array $seen = [];
    private string $projectDir = '';
    private string $outputDir = '';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function copy(AppConfig $config, string $to): void
    {
        $this->exclude = $config->getExcludes();
        $this->include = $config->getIncludes();
        $this->seen = [];
        $projectDir = $config->getProjectDir();
        $this->projectDir = $this->real($projectDir) ?? self::slashes($projectDir);
        $this->outputDir = $this->real($config->getOutputDir()) ?? self::slashes($config->getOutputDir());

        foreach ($config->getIncludes() as $include) {
            $source = '.' === $include ? $projectDir : $projectDir.'/'.$include;
            if (file_exists($source)) {
                $this->visit($source, '.' === $include ? '' : $include, $to);
            }
        }

        if (!is_file($to.'/'.$config->getEntry())) {
            throw new InvalidArgumentException(\sprintf('The entry script "%s" is not shipped: add it to "build.include".', $config->getEntry()));
        }
    }

    /**
     * Copies a path when it ships, once: an included folder may hold a path that is included too.
     */
    private function visit(string $path, string $relative, string $to): void
    {
        if (isset($this->seen[$relative])) {
            return;
        }
        $this->seen[$relative] = true;
        if ('' === $relative || $this->ships($path, $relative)) {
            $this->copyPath($path, $relative, $to);
        }
    }

    /**
     * Copies a file, or a folder with what it ships; $relative is its path in the project, '' for the project.
     */
    private function copyPath(string $path, string $relative, string $to): void
    {
        $copy = '' === $relative ? $to : $to.'/'.$relative;
        $real = $this->real($path) ?? $path;
        if (!is_dir($real)) {
            $this->filesystem->copy($path, $copy, true);
            if (is_executable($path)) {
                $this->filesystem->chmod($copy, 0o755);
            }

            return;
        }

        $this->filesystem->mkdir($copy);
        foreach (scandir($real) ?: [] as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }
            $this->visit($path.'/'.$name, '' === $relative ? $name : $relative.'/'.$name, $to);
        }
    }

    /**
     * Whether a file or folder is shipped; $relative is its path in the project, as excludes are written.
     */
    private function ships(string $path, string $relative): bool
    {
        $real = $this->real($path) ?? '';
        $included = \in_array($relative, $this->include, true);
        if ($included && self::matches(self::NEVER_SHIPPED, $relative)) {
            return $this->neverShips($relative);
        }
        // Neither what is excluded nor the packages being built
        if (!($included && str_ends_with($relative, '.decrypt.private.php')) && ($this->inside($real, $this->outputDir) || $this->excluded($relative))) {
            return false;
        }
        if (str_ends_with($relative, '.decrypt.private.php')) {
            throw new InvalidArgumentException(\sprintf('"%s" is a key that decrypts the secrets of the application: it must never be shipped. Exclude it in "build.exclude".', $relative));
        }
        if (self::matches(self::NEVER_SHIPPED, $relative)) {
            return $this->neverShips($relative);
        }
        if (self::matches(self::ENV_DUMPS, $relative)) {
            if (!$included) {
                $this->logger->warning('"{path}" may hold secrets and is not shipped: list it in "build.include" to ship it, or set APP_ENV with "runtime.env.set"', ['path' => $relative]);

                return false;
            }
            $this->logger->warning('"{path}" may hold secrets and is shipped, as "build.include" lists it', ['path' => $relative]);
        }
        // Reached through a link to a folder, a path has another one in the project, where excludes apply
        // too: to it and to every folder above it
        if ($this->inside($real, $this->projectDir) && $real !== $this->projectDir) {
            $realRelative = substr($real, \strlen(rtrim($this->projectDir, '/')) + 1);
            if ($realRelative !== $relative) {
                if (str_ends_with($realRelative, '.decrypt.private.php')) {
                    throw new InvalidArgumentException(\sprintf('"%s" links to a key that decrypts the secrets of the application ("%s"): it must never be shipped.', $relative, $realRelative));
                }
                if (self::matches([...self::NEVER_SHIPPED, ...self::ENV_DUMPS], $realRelative) && !$this->excluded($realRelative)) {
                    // A link to a dump ships like the dump, when "build.include" lists the link
                    if (!$included || !self::matches(self::ENV_DUMPS, $realRelative)) {
                        $this->logger->warning('"{path}" links to "{target}", which may hold secrets and is never shipped', ['path' => $relative, 'target' => $realRelative]);

                        return false;
                    }
                    if (!self::matches(self::ENV_DUMPS, $relative)) {
                        $this->logger->warning('"{path}" links to "{target}", which may hold secrets, and is shipped, as "build.include" lists it', ['path' => $relative, 'target' => $realRelative]);
                    }
                }
                for ($ancestor = $realRelative; '.' !== $ancestor; $ancestor = \dirname($ancestor)) {
                    if ($this->excluded($ancestor)) {
                        return false;
                    }
                }
            }
        }
        if (!$this->isLink($path, $real)) {
            return true;
        }

        // A link is followed when it stays in the project, like a Composer path repository,
        // and what it points to is checked like the file itself
        if (!$this->inside($real, $this->projectDir)) {
            $this->logger->warning('"{path}" links out of the project and is not shipped', ['path' => $relative]);

            return false;
        }
        $target = $real === $this->projectDir ? '.' : substr($real, \strlen(rtrim($this->projectDir, '/')) + 1);
        // A link to a folder it was reached through, directly or through other links, would be copied without end
        if (is_dir($real)) {
            for ($dir = \dirname($path), $depth = substr_count($relative, '/'); $depth >= 0; $dir = \dirname($dir), --$depth) {
                if ($this->inside($this->real($dir) ?? '', $real)) {
                    $this->logger->warning('"{path}" links to a folder it is in and is not shipped', ['path' => $relative]);

                    return false;
                }
            }
        }

        return '.' === $target || !$this->excluded($target);
    }

    private function isLink(string $path, string $real): bool
    {
        if (is_link($path)) {
            return true;
        }
        if ('\\' !== \DIRECTORY_SEPARATOR) {
            return false;
        }
        // A junction, or one that leads nowhere
        $parent = $this->real(\dirname($path));

        return '' === $real || null === $parent || 0 !== strcasecmp($real, rtrim($parent, '/').'/'.basename($path));
    }

    private function excluded(string $relative): bool
    {
        foreach ($this->exclude as $pattern) {
            foreach (explode('/', $relative) as $segment) {
                if (fnmatch($pattern, $segment)) {
                    return true;
                }
            }
            if (fnmatch($pattern, $relative)) {
                return true;
            }
        }

        return false;
    }

    private function neverShips(string $relative): false
    {
        if ('auth.json' === basename($relative)) {
            $this->logger->warning('"{path}" holds the credentials of Composer and never ships', ['path' => $relative]);
        } else {
            $this->logger->warning('"{path}" may hold secrets and never ships: set APP_ENV with "runtime.env.set", or list the .env.local.php of "composer dump-env prod" in "build.include"', ['path' => $relative]);
        }

        return false;
    }

    /**
     * @param list<string> $patterns
     */
    private static function matches(array $patterns, string $relative): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, basename($relative))) {
                return true;
            }
        }

        return false;
    }

    private function inside(string $path, string $dir): bool
    {
        return $path === $dir || str_starts_with($path, rtrim($dir, '/').'/');
    }

    private function real(string $path): ?string
    {
        $real = realpath($path);

        return false === $real ? null : self::slashes($real);
    }

    private static function slashes(string $path): string
    {
        return '\\' === \DIRECTORY_SEPARATOR ? str_replace('\\', '/', $path) : $path;
    }
}
