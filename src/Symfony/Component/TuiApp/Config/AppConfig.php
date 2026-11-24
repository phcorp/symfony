<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Config;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\String\UnicodeString;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;
use Symfony\Component\TuiApp\Exception\LogicException;

/**
 * The processed configuration of a native application, with the defaults
 * that depend on the project resolved.
 *
 * @experimental
 */
final class AppConfig
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config The configuration processed by Configuration
     */
    public function __construct(array $config, private readonly string $projectDir)
    {
        $composer = is_file($projectDir.'/composer.json') ? json_decode((string) file_get_contents($projectDir.'/composer.json'), true) : null;
        $package = \is_array($composer) && \is_string($composer['name'] ?? null) ? $composer['name'] : null;

        $config['app']['name'] ??= ucwords(str_replace(['-', '_'], ' ', basename($package ?? $projectDir)));
        $config['app']['id'] ??= self::idFrom($package ?? $config['app']['name']);
        $config['app']['description'] ??= \is_array($composer) && \is_string($composer['description'] ?? null) ? $composer['description'] : null;
        $config['window']['title'] ??= $config['app']['name'];
        $config['build']['targets'] = $config['build']['targets'] ?: [self::currentTarget()];

        $this->config = $config;
    }

    /**
     * The target of the computer this runs on, like macos-arm64.
     */
    public static function currentTarget(): string
    {
        $os = match (\PHP_OS_FAMILY) {
            'Darwin' => 'macos',
            'Windows' => 'windows',
            default => 'linux',
        };
        $arm = \in_array(strtolower(php_uname('m')), ['arm64', 'aarch64'], true);

        return $os.'-'.($arm ? 'arm64' : ('windows' === $os ? 'x64' : 'x86_64'));
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }

    public function getName(): string
    {
        return $this->config['app']['name'];
    }

    public function getId(): string
    {
        return $this->config['app']['id'];
    }

    public function getVersion(): string
    {
        return $this->config['app']['version'];
    }

    /**
     * The script that starts the application, relative to the project directory.
     */
    public function getEntry(): string
    {
        return $this->config['app']['entry'];
    }

    /**
     * The icon of a system: the one of "app.icons", else "app.icon"; an absolute path, or null.
     */
    public function getIcon(string $os): ?string
    {
        $icon = $this->config['app']['icons'][$os] ?? $this->config['app']['icon'];

        return null === $icon ? null : $this->path($icon);
    }

    public function getRuntimeMode(): string
    {
        return $this->config['runtime']['mode'];
    }

    /**
     * The PHP version the embedded runtime builds, or the oldest one the other runtimes run on.
     */
    public function getPhpVersion(): string
    {
        return 'embedded' === $this->getRuntimeMode() ? $this->config['runtime']['php']['version'] : $this->config['runtime']['php']['min_version'];
    }

    /**
     * The PHP binary of the custom and system runtimes, as configured.
     */
    public function getPhpPath(): ?string
    {
        return $this->config['runtime']['php']['path'];
    }

    /**
     * @return list<string>
     */
    public function getPhpExtensions(): array
    {
        return $this->config['runtime']['php']['extensions'];
    }

    /**
     * @return array{binary: string, version: string, flags: list<string>, heap_size: int|null, native: list<string>}
     */
    public function getElephc(): array
    {
        return $this->config['runtime']['elephc'];
    }

    /**
     * @return list<string>
     */
    public function getIncludes(): array
    {
        return $this->config['build']['include'];
    }

    /**
     * @return list<string>
     */
    public function getExcludes(): array
    {
        return $this->config['build']['exclude'];
    }

    /**
     * @return list<string>
     */
    public function getFormats(string $os): array
    {
        return $this->config['build']['formats'][$os];
    }

    /**
     * The bundled native host sources and packaging scripts.
     */
    public function getHostsDir(): string
    {
        return \dirname(__DIR__).'/Resources';
    }

    /**
     * The command that runs the Linux builds of an architecture elsewhere, or null.
     *
     * @return list<string>|null
     */
    public function getLinuxRunner(string $arch): ?array
    {
        $runner = $this->config['build']['hosts']['linux_runners'][$arch] ?? null;

        return null === $runner ? null : array_values($runner);
    }

    /**
     * @return array{identity: string|null, notarize: bool, keychain_profile: string|null}
     */
    public function getMacosSigning(): array
    {
        return $this->config['signing']['macos'];
    }

    /**
     * @return array{certificate: string|null, password_env: string, timestamp_url: string}
     */
    public function getWindowsSigning(): array
    {
        return $this->config['signing']['windows'];
    }

    /**
     * @return list<string>
     */
    public function getTargets(): array
    {
        return $this->config['build']['targets'];
    }

    public function getOutputDir(): string
    {
        return $this->path($this->config['build']['output']);
    }

    /**
     * An absolute path, from one relative to the project directory.
     */
    public function path(string $path): string
    {
        if (!class_exists(Path::class)) {
            throw new LogicException('Resolving the paths of the application requires the Filesystem component. Try running "composer require symfony/filesystem".');
        }
        $projectDir = Path::isAbsolute($this->projectDir) ? $this->projectDir : Path::makeAbsolute($this->projectDir, getcwd() ?: '/');

        return Path::makeAbsolute($path, $projectDir);
    }

    /**
     * Checks what the build needs from the filesystem.
     *
     * @return list<string> Warnings
     *
     * @throws InvalidArgumentException When something the build needs is missing
     */
    public function check(): array
    {
        $warnings = [];
        if (\in_array('android-arm64', $this->getTargets(), true) && !preg_match('/^[A-Za-z][A-Za-z0-9_]*(\\.[A-Za-z][A-Za-z0-9_]*)+$/D', $this->getId())) {
            throw new InvalidArgumentException('Android app.id must have dot-separated Java identifiers, using letters, digits and underscores.');
        }

        if (!is_file($entry = $this->path($this->config['app']['entry']))) {
            throw new InvalidArgumentException(\sprintf('The entry script "%s" does not exist; set "app.entry".', $entry));
        }

        if (array_intersect($this->getTargets(), ['ios-arm64', 'ios-simulator-arm64'])) {
            $profile = $this->config['signing']['ios']['provisioning_profile'];
            if (null !== $profile && !is_file($this->path($profile))) {
                throw new InvalidArgumentException(\sprintf('The iOS provisioning profile "%s" does not exist.', $this->path($profile)));
            }
            if (!preg_match('/^\d+(\.\d+){0,2}$/D', preg_split('/[-+]/', $this->getVersion())[0])) {
                throw new InvalidArgumentException('iOS app.version must have at most three numeric components before its prerelease or build suffix.');
            }
        }

        $icon = $this->config['app']['icon'];
        if (null === $icon) {
            $warnings[] = 'No icon is configured: the application uses a placeholder. Set "app.icon" to an SVG, or a PNG of at least 512×512 pixels, 1024×1024 for Retina displays.';
        } elseif (!is_file($this->path($icon))) {
            throw new InvalidArgumentException(\sprintf('The icon "%s" does not exist.', $this->path($icon)));
        } elseif (str_ends_with(strtolower($icon), '.png')) {
            $size = @getimagesize($this->path($icon));
            if (false === $size) {
                throw new InvalidArgumentException(\sprintf('The icon "%s" is not a PNG image.', $this->path($icon)));
            }
            if ($size[0] < 512 || $size[1] < 512) {
                throw new InvalidArgumentException(\sprintf('The icon "%s" is %d×%d pixels; it must be at least 512×512, and 1024×1024 for Retina displays.', $this->path($icon), $size[0], $size[1]));
            }
            if ($size[0] < 1024 || $size[1] < 1024) {
                $warnings[] = \sprintf('The icon is %d×%d pixels: it looks blurry on Retina displays, which need 1024×1024.', $size[0], $size[1]);
            }
        }

        foreach ($this->config['app']['icons'] as $system => $path) {
            if (null !== $path && !is_file($this->path($path))) {
                throw new InvalidArgumentException(\sprintf('The %s icon "%s" does not exist.', $system, $this->path($path)));
            }
        }

        return $warnings;
    }

    /**
     * The manifest the host of a target reads, see Resources/contract/manifest.schema.json.
     *
     * @return array<string, mixed>
     */
    public function toManifest(string $target): array
    {
        $app = $this->config['app'];
        $window = $this->config['window'];
        $runtime = $this->config['runtime'];
        $windows = str_starts_with($target, 'windows-');
        $mobile = str_starts_with($target, 'ios-') || str_starts_with($target, 'android-');

        $ini = [];
        foreach ($runtime['php']['ini'] as $name => $value) {
            array_push($ini, '-d', $name.'='.$value);
        }
        $entry = './app/'.$app['entry'];

        $launch = match ($runtime['mode']) {
            'embedded', 'custom' => ['mode' => $runtime['mode'], 'command' => $mobile ? null : ($windows ? 'runtime/php.exe' : 'runtime/php'), 'args' => ['-n', ...$ini, $entry, ...$app['args']], 'php' => null],
            'system' => ['mode' => 'system', 'command' => null, 'args' => [...$ini, $entry, ...$app['args']], 'php' => ['path' => $runtime['php']['path'], 'minVersion' => $runtime['php']['min_version']]],
            'elephc' => ['mode' => 'elephc', 'command' => 'app/'.$this->getExecutableName(), 'args' => $app['args'], 'php' => null],
            default => throw new InvalidArgumentException(\sprintf('Unknown runtime mode "%s".', $runtime['mode'])),
        };
        $launch['env'] = ['pass' => $runtime['env']['pass'], 'set' => (object) $runtime['env']['set']];

        return [
            'manifest' => 1,
            'app' => [
                'name' => $app['name'],
                'id' => $app['id'],
                'version' => $app['version'],
                'description' => $app['description'],
                'publisher' => $app['publisher'],
                'copyright' => $app['copyright'],
                'homepage' => $app['homepage'],
                'category' => $app['category'],
                'singleInstance' => $app['single_instance'],
                'fileAssociations' => array_map(static fn (array $association): array => [
                    'extension' => $association['extension'],
                    'name' => $association['name'],
                    'mimeType' => $association['mime_type'],
                    'role' => $association['role'],
                ], $app['file_associations']),
                'urlSchemes' => $app['url_schemes'],
            ],
            'window' => [
                'title' => $window['title'],
                'width' => $window['width'],
                'height' => $window['height'],
                'minWidth' => $window['min_width'],
                'minHeight' => $window['min_height'],
                'resizable' => $window['resizable'],
                'rememberGeometry' => $window['remember_geometry'],
                'theme' => $window['theme'],
                'canvasFont' => ['family' => $window['canvas_font']['family'], 'size' => $window['canvas_font']['size']],
            ],
            'menu' => $this->config['menu'],
            'launch' => $launch,
            'logging' => $this->config['logging'],
        ];
    }

    /**
     * The file name of the executable elephc makes.
     */
    public function getExecutableName(): string
    {
        return self::slug(self::ascii($this->getName())) ?: 'app';
    }

    private static function idFrom(string $name): string
    {
        $parts = array_values(array_filter(array_map(static fn (string $part): string => self::slug(self::ascii($part, $name)), preg_split('{[/.\s]+}', $name) ?: [])));
        $id = implode('.', \count($parts) > 1 ? $parts : ['app', ...($parts ?: ['app'])]);

        if (!preg_match(Configuration::ID_PATTERN, $id)) {
            throw new InvalidArgumentException(\sprintf('No application id can be made from "%s": set "app.id".', $name));
        }

        return $id;
    }

    /**
     * A name, or a part of it, in ASCII: transliterated by the String component, which a name that is not ASCII needs.
     */
    private static function ascii(string $part, ?string $name = null): string
    {
        if (class_exists(UnicodeString::class)) {
            return (new UnicodeString($part))->ascii()->toString();
        }
        if (preg_match('/[^\x00-\x7F]/', $part)) {
            throw new LogicException(\sprintf('Naming the application from "%s" requires the String component, as it is not ASCII. Try running "composer require symfony/string", or set "app.id".', $name ?? $part));
        }

        return $part;
    }

    /**
     * Lowercase ASCII letters and digits separated by hyphens.
     */
    private static function slug(string $ascii): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
    }
}
