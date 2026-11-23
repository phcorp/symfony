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

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\TuiApp\Build\VersionConstraint;

/**
 * The configuration of a native application: what it is, its window, how
 * it runs and how it is built.
 *
 * FrameworkBundle reads it under framework.tui_app; ConfigLoader reads it
 * from a tui_app.yaml or tui_app.php file without a kernel.
 *
 * @experimental
 */
final class Configuration implements ConfigurationInterface
{
    public const TARGETS = ['macos-arm64', 'macos-x86_64', 'linux-x86_64', 'linux-arm64', 'windows-x64', 'windows-arm64', 'ios-arm64', 'ios-simulator-arm64', 'android-arm64'];
    public const ELEPHC_TARGETS = ['macos-arm64', 'linux-x86_64', 'linux-arm64'];
    public const CATEGORIES = ['business', 'developer-tools', 'education', 'entertainment', 'finance', 'games', 'graphics', 'lifestyle', 'music', 'news', 'productivity', 'social', 'utilities', 'video'];
    public const DEFAULT_EXTENSIONS = ['ctype', 'filter', 'iconv', 'mbstring', 'pcntl', 'posix', 'tokenizer'];
    public const DEFAULT_EXCLUDES = ['tests', 'Tests', '*.md', '.git*', 'var', '.env.local', '.env.*.local', 'auth.json', '*.decrypt.private.php'];

    /**
     * @internal
     */
    public const ID_PATTERN = '/^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/D';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tui_app');
        self::addOptions($treeBuilder->getRootNode());

        return $treeBuilder;
    }

    /**
     * A path inside the project, with "/" separators and no "./": "." for the project itself.
     */
    private static function relativePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $i => $segment) {
            if ('..' === $segment || (0 === $i && ('' === $segment || preg_match('/^[a-zA-Z]:$/', $segment)))) {
                throw new \InvalidArgumentException(\sprintf('"%s" must be a path inside the project directory.', $path));
            }
            if ('' !== $segment && '.' !== $segment) {
                $segments[] = $segment;
            }
        }

        return $segments ? implode('/', $segments) : '.';
    }

    /**
     * Adds the options to a node, the root of the tui_app tree or a section of another configuration.
     */
    public static function addOptions(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Runs the Tui application as a desktop or mobile application, with native controls')
            ->children()
                ->arrayNode('app')
                    ->info('What the application is')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->stringNode('name')->info('The name shown in the menu bar, the dock or taskbar and the installer; defaults to the project directory name')->defaultNull()->end()
                        ->stringNode('id')
                            ->info('A reverse-DNS identifier, like com.acme.notes: the bundle identifier on macOS, the application id on Linux and Windows; defaults to one made from the name')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (?string $id): bool => null !== $id && '' !== $id && !preg_match(self::ID_PATTERN, $id))
                                ->thenInvalid('The application id %s is not a reverse-DNS identifier like "com.acme.notes".')
                            ->end()
                        ->end()
                        ->stringNode('version')
                            ->info('The version of the application, like 1.2.0')
                            ->defaultValue('1.0.0')
                            ->validate()
                                ->ifTrue(static fn (string $version): bool => '' !== $version && !preg_match('/^\d+(\.\d+){0,3}(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/D', $version))
                                ->thenInvalid('The application version %s is not a version like 1.2.0.')
                            ->end()
                        ->end()
                        ->stringNode('description')->defaultNull()->end()
                        ->stringNode('publisher')->info('Who publishes the application, shown by installers')->defaultNull()->end()
                        ->stringNode('copyright')->defaultNull()->end()
                        ->stringNode('homepage')->defaultNull()->end()
                        ->enumNode('category')->info('Where the application is listed: the macOS category, the Linux desktop categories')->values(self::CATEGORIES)->defaultValue('utilities')->end()
                        ->stringNode('icon')
                            ->info('An SVG, or a PNG of at least 512×512 pixels, 1024×1024 for Retina displays, from which the icon of every system is made')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (?string $icon): bool => null !== $icon && !preg_match('/\.(png|svg)$/i', $icon))
                                ->thenInvalid('The icon %s must be a PNG or an SVG file.')
                            ->end()
                        ->end()
                        ->arrayNode('icons')
                            ->info('Icons that replace the one made from "icon" on a system')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->stringNode('macos')->info('An .icns file')->defaultNull()->end()
                                ->stringNode('windows')->info('An .ico file')->defaultNull()->end()
                                ->stringNode('linux')->info('A PNG or an SVG file')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->stringNode('entry')->info('The PHP script that starts the application, relative to the project directory')->defaultValue('bin/app.php')->cannotBeEmpty()->validate()->always(self::relativePath(...))->end()->end()
                        ->arrayNode('args')->info('Arguments given to the entry script')->stringPrototype()->beforeNormalization()->ifTrue(self::isScalar(...))->then(self::toString(...))->end()->end()->end()
                        ->arrayNode('file_associations', 'file_association')
                            ->info('The files the application opens, sent to it with OpenEvent')
                            ->arrayPrototype()
                                ->children()
                                    ->stringNode('extension')
                                        ->info('Like "acme", or "tar.gz"')
                                        ->isRequired()
                                        ->cannotBeEmpty()
                                        ->validate()->always(static fn (string $extension): string => ltrim($extension, '.'))->end()
                                        ->validate()
                                            ->ifTrue(static fn (string $extension): bool => '' !== $extension && !preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/D', $extension))
                                            ->thenInvalid('%s is not a file extension, like "acme" or "tar.gz".')
                                        ->end()
                                    ->end()
                                    ->stringNode('name')->info('The name of the file type, like "Acme note"')->isRequired()->end()
                                    ->stringNode('mime_type')->defaultNull()->end()
                                    ->enumNode('role')->values(['editor', 'viewer'])->defaultValue('editor')->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('url_schemes', 'url_scheme')
                            ->info('The URL schemes the application opens, like "acme" for acme://...')
                            ->stringPrototype()
                                ->validate()
                                    ->ifTrue(static fn (string $scheme): bool => '' !== $scheme && !preg_match('/^[a-z][a-z0-9+.-]*$/D', $scheme))
                                    ->thenInvalid('%s is not a URL scheme, like "acme": a lowercase letter, then lowercase letters, digits, "+", "." or "-".')
                                ->end()
                            ->end()
                        ->end()
                        ->booleanNode('single_instance')->info('Whether a second launch hands what it opens to the running application and exits')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('window')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->stringNode('title')->info('Defaults to the name of the application')->defaultNull()->end()
                        ->integerNode('width')->info('The width of the content, in pixels')->min(1)->defaultValue(900)->end()
                        ->integerNode('height')->min(1)->defaultValue(640)->end()
                        ->integerNode('min_width')->min(1)->defaultNull()->end()
                        ->integerNode('min_height')->min(1)->defaultNull()->end()
                        ->booleanNode('resizable')->defaultTrue()->end()
                        ->booleanNode('remember_geometry')->info('Whether the window opens where and as large as it was closed')->defaultTrue()->end()
                        ->enumNode('theme')->info('"system" follows the light or dark appearance of the system')->values(['system', 'light', 'dark'])->defaultValue('system')->end()
                        ->arrayNode('canvas_font')
                            ->info('The monospaced font of widgets drawn as text, and of the cells sizes are counted in')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->stringNode('family')->info('Defaults to the monospaced font of the system')->defaultNull()->end()
                                ->floatNode('size')->info('In points')->min(6)->max(72)->defaultValue(13)->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('menu')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('native')->info('Whether the menus set with Application::setMenus() show in the menu bar')->defaultTrue()->end()
                        ->booleanNode('about')->info('Whether the host adds an About item')->defaultTrue()->end()
                        ->booleanNode('quit')->info('Whether the host adds a Quit item')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('runtime')
                    ->info('What runs the application')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('mode')
                            ->info('embedded: a static PHP built for the application and shipped with it; custom: a PHP binary you give, shipped with it; system: the PHP installed on the computer; elephc (beta): the application compiled to a native executable with elephc')
                            ->values(['embedded', 'custom', 'system', 'elephc'])
                            ->defaultValue('embedded')
                        ->end()
                        ->arrayNode('php')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->stringNode('version')->info('embedded: the PHP version to build, like 8.5 or 8.5.12')->defaultValue('8.5')->cannotBeEmpty()->validate()->always(self::phpVersion(...))->end()->end()
                                ->stringNode('min_version')->info('system and custom: the oldest PHP version the application runs on')->defaultValue('8.4.1')->cannotBeEmpty()->validate()->always(self::phpVersion(...))->end()->end()
                                ->stringNode('path')->info('custom: the PHP binary to ship; system: the PHP binary to run instead of the one on the PATH')->defaultNull()->end()
                                ->arrayNode('extensions', 'extension')
                                    ->info('embedded: the extensions built in; custom: the extensions the binary must have')
                                    ->stringPrototype()
                                        ->beforeNormalization()->ifString()->then(strtolower(...))->end()
                                        ->validate()
                                            ->ifTrue(static fn (string $extension): bool => '' !== $extension && !preg_match('/^[a-z0-9_]+$/D', $extension))
                                            ->thenInvalid('%s is not an extension name, like "mbstring".')
                                        ->end()
                                    ->end()
                                    ->defaultValue(self::DEFAULT_EXTENSIONS)
                                ->end()
                                ->arrayNode('ini')
                                    ->info('INI settings, like memory_limit: 256M')
                                    ->useAttributeAsKey('name')
                                    ->normalizeKeys(false)
                                    ->stringPrototype()
                                        ->beforeNormalization()->ifTrue(self::isScalar(...))->then(self::toString(...))->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('elephc')
                            ->info('elephc (beta): the compiler and its options')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->stringNode('binary')->defaultValue('elephc')->end()
                                ->stringNode('version')
                                    ->info('The versions of elephc the application compiles with, like ^0.27 or ">=0.27 <0.29"')
                                    ->defaultValue('^0.27')
                                    ->validate()
                                        ->ifTrue(static fn (string $constraint): bool => '' !== $constraint && !VersionConstraint::isValid($constraint))
                                        ->thenInvalid('%s is not a version constraint, like ^0.27, ~0.27.1 or ">=0.27 <0.29".')
                                    ->end()
                                ->end()
                                ->arrayNode('flags')->info('More options given to elephc')->stringPrototype()->beforeNormalization()->ifTrue(self::isScalar(...))->then(self::toString(...))->end()->end()->end()
                                ->integerNode('heap_size')->info('The heap size given to elephc, in bytes')->min(1)->defaultNull()->end()
                                ->arrayNode('native')->info('Native libraries added with "elephc native add"')->stringPrototype()->end()->defaultValue(['pcre2'])->end()
                            ->end()
                        ->end()
                        ->arrayNode('env')
                            ->info('The environment of the application: the host keeps only usual variables (PATH, HOME, LANG...), those in "pass", and adds those in "set"')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('pass')->stringPrototype()->end()->end()
                                ->arrayNode('set')->useAttributeAsKey('name')->normalizeKeys(false)->stringPrototype()->beforeNormalization()->ifTrue(self::isScalar(...))->then(self::toString(...))->end()->end()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('build')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('targets', 'target')
                            ->info('The systems to build for; a target builds on its own system, except Linux ones, which build anywhere in a container. Defaults to the system of the computer')
                            ->enumPrototype()->values(self::TARGETS)->end()
                            ->validate()
                                ->ifTrue(static fn (array $targets): bool => \count($targets) !== \count(array_unique($targets)))
                                ->thenInvalid('A target is listed more than once in %s.')
                            ->end()
                        ->end()
                        ->stringNode('output')
                            ->info('Where the packages are written, one directory per target, inside the project directory')
                            ->defaultValue('build/app')
                            ->cannotBeEmpty()
                            ->validate()->always(static fn (string $path): string => '.' === ($path = self::relativePath($path)) ? throw new \InvalidArgumentException('"build.output" must be a path inside the project directory, not the project directory itself.') : $path)->end()
                        ->end()
                        ->arrayNode('include')->info('What is shipped, relative to the project directory')->stringPrototype()->validate()->always(self::relativePath(...))->end()->end()->defaultValue(['bin', 'config', 'src', 'vendor', 'composer.json'])->end()
                        ->arrayNode('exclude')->info(\sprintf('Glob patterns of what is not shipped, on top of those of "exclude_defaults": %s', implode(', ', self::DEFAULT_EXCLUDES)))->stringPrototype()->end()->end()
                        ->booleanNode('exclude_defaults')->info('Whether the default patterns are excluded too. Either way, .env.local, .env.*.local and auth.json never ship, even listed in "include"; .env.local.php and .env.*.local.php ship only when "include" lists their path; a key that decrypts the secrets stops the build')->defaultTrue()->end()
                        ->arrayNode('formats')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('macos')->enumPrototype()->values(['app', 'dmg', 'zip'])->end()->defaultValue(['app', 'dmg'])->end()
                                ->arrayNode('linux')->enumPrototype()->values(['tar', 'deb', 'rpm'])->end()->defaultValue(['tar', 'deb', 'rpm'])->end()
                                ->arrayNode('windows')->enumPrototype()->values(['exe', 'zip'])->end()->defaultValue(['exe'])->end()
                                ->arrayNode('ios')->enumPrototype()->values(['app'])->end()->defaultValue(['app'])->end()
                                ->arrayNode('android')->enumPrototype()->values(['apk'])->end()->defaultValue(['apk'])->end()
                            ->end()
                        ->end()
                        ->arrayNode('hosts')
                            ->info('The desktop and mobile native hosts')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('linux_runners')
                                    ->info('Commands that run the Linux builds of an architecture (x86_64, arm64) elsewhere than on this computer, like [orb, -m, my-machine] or [docker, run, --rm, -v, /src:/src, my-image]; they must see the paths of this computer')
                                    ->useAttributeAsKey('arch')
                                    ->arrayPrototype()->stringPrototype()->end()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('signing')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('macos')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->stringNode('identity')->info('A "Developer ID Application" identity of the keychain; null signs ad hoc')->defaultNull()->end()
                                ->booleanNode('notarize')->info('Needs "identity" and "keychain_profile"')->defaultFalse()->end()
                                ->stringNode('keychain_profile')->info('The notarytool keychain profile used to notarize')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->arrayNode('ios')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->stringNode('identity')->info('A development signing identity; null signs ad hoc for simulator builds')->defaultNull()->end()
                                ->stringNode('provisioning_profile')->info('The development provisioning profile for installation on iOS devices')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->arrayNode('windows')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->stringNode('certificate')->info('A .pfx file; null does not sign')->defaultNull()->end()
                                ->stringNode('password_env')->info('The environment variable that holds the password of the certificate')->defaultValue('TUI_APP_SIGNING_PASSWORD')->end()
                                ->stringNode('timestamp_url')->defaultValue('http://timestamp.digicert.com')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('logging')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->stringNode('file')->info('Where the host writes what the application prints; defaults to the system log')->defaultNull()->end()
                        ->enumNode('level')->values(['debug', 'info', 'warning', 'error'])->defaultValue('warning')->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => (null === $config['signing']['ios']['identity']) !== (null === $config['signing']['ios']['provisioning_profile']))
                ->thenInvalid('iOS signing needs both an identity and a provisioning profile.')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => 'embedded' !== $config['runtime']['mode'] && (bool) array_intersect($config['build']['targets'], ['ios-arm64', 'ios-simulator-arm64', 'android-arm64']))
                ->thenInvalid('Mobile targets require the embedded PHP runtime.')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => 'elephc' === $config['runtime']['mode'] && array_diff($config['build']['targets'], self::ELEPHC_TARGETS))
                ->then(static function (array $config): never {
                    throw new \InvalidArgumentException(\sprintf('The elephc runtime builds for %s only, not for %s.', implode(', ', self::ELEPHC_TARGETS), implode(', ', array_diff($config['build']['targets'], self::ELEPHC_TARGETS))));
                })
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => (bool) array_diff(array_keys($config['build']['hosts']['linux_runners']), ['x86_64', 'arm64']))
                ->thenInvalid('The Linux runners are keyed by architecture: x86_64 or arm64.')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => 'custom' === $config['runtime']['mode'] && null === $config['runtime']['php']['path'])
                ->thenInvalid('The custom runtime needs the PHP binary to ship in "runtime.php.path".')
            ->end()
            ->validate()->always(self::validate(...))->end()
        ;
    }

    /**
     * The rules between options.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function validate(array $config): array
    {
        foreach (['width', 'height'] as $size) {
            $min = $config['window']['min_'.$size];
            if (\is_int($min) && \is_int($config['window'][$size]) && $min > $config['window'][$size]) {
                throw new \InvalidArgumentException(\sprintf('"window.min_%s" (%d) is larger than "window.%1$s" (%d).', $size, $min, $config['window'][$size]));
            }
        }

        $macos = $config['signing']['macos'];
        foreach ($macos['notarize'] ? ['identity', 'keychain_profile'] : [] as $option) {
            if (null === $macos[$option]) {
                throw new \InvalidArgumentException(\sprintf('Notarizing needs "signing.macos.%s".', $option));
            }
        }

        if ('elephc' === $config['runtime']['mode'] && !str_ends_with($config['app']['entry'], '.php')) {
            throw new \InvalidArgumentException(\sprintf('The entry script "%s" must end with ".php" for the elephc runtime, which names the executable after it.', $config['app']['entry']));
        }

        if ($config['build']['exclude_defaults']) {
            $config['build']['exclude'] = array_values(array_unique([...self::DEFAULT_EXCLUDES, ...$config['build']['exclude']]));
        }

        return $config;
    }

    private static function phpVersion(string $version): string
    {
        if ('' === $version) {
            return $version;
        }
        if (!preg_match('/^\d+\.\d+(\.\d+)?$/D', $version)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a PHP version, like 8.5 or 8.5.12.', $version));
        }
        if (version_compare($version, '8.4', '<')) {
            throw new \InvalidArgumentException(\sprintf('The application needs PHP 8.4 or later, not %s.', $version));
        }

        return $version;
    }

    /**
     * Whether a value is a boolean or a number, which a string option takes as a string.
     */
    private static function isScalar(mixed $value): bool
    {
        return \is_bool($value) || \is_int($value) || \is_float($value);
    }

    private static function toString(bool|int|float $value): string
    {
        return \is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
