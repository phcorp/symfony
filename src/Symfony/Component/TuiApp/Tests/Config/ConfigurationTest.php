<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Config;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Config\ConfigLoader;
use Symfony\Component\TuiApp\Config\Configuration;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;

class ConfigurationTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/tui-app-config-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir.'/bin', 0o777, true);
        file_put_contents($this->projectDir.'/bin/app.php', '<?php');
        file_put_contents($this->projectDir.'/composer.json', json_encode(['name' => 'acme/notes-app', 'description' => 'Notes, in a window']));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testDefaultsComeFromTheProject()
    {
        $config = (new ConfigLoader())->load($this->projectDir);

        $this->assertSame('Notes App', $config->getName());
        $this->assertSame('acme.notes-app', $config->getId());
        $this->assertSame('embedded', $config->getRuntimeMode());
        $this->assertSame([AppConfig::currentTarget()], $config->getTargets());
        $this->assertSame($this->projectDir.'/build/app', $config->getOutputDir());
        $this->assertSame('Notes App', $config->toArray()['window']['title']);
        $this->assertSame('Notes, in a window', $config->toArray()['app']['description']);
    }

    public function testYamlFile()
    {
        file_put_contents($this->projectDir.'/tui_app.yaml', <<<'YAML'
            tui_app:
              app:
                name: Acme Notes
                id: com.acme.notes
                version: 1.2.0
                file_associations:
                  - { extension: .acme, name: Acme note }
                url_schemes: [acme]
              window: { width: 1000, theme: dark }
              runtime:
                mode: system
                php: { ini: { memory_limit: 256M } }
              build:
                targets: [macos-arm64, linux-x86_64]
            YAML);

        $config = (new ConfigLoader())->load($this->projectDir);

        $this->assertSame('com.acme.notes', $config->getId());
        $this->assertSame(['macos-arm64', 'linux-x86_64'], $config->getTargets());
        $manifest = $config->toManifest('linux-x86_64');
        $this->assertSame(['mode' => 'system', 'command' => null, 'args' => ['-d', 'memory_limit=256M', './app/bin/app.php'], 'php' => ['path' => null, 'minVersion' => '8.4.1']], array_diff_key($manifest['launch'], ['env' => 1]));
        $this->assertSame([['extension' => 'acme', 'name' => 'Acme note', 'mimeType' => null, 'role' => 'editor']], $manifest['app']['fileAssociations']);
        $this->assertSame(1000, $manifest['window']['width']);
    }

    public function testPhpFile()
    {
        file_put_contents($this->projectDir.'/tui_app.php', '<?php return ["app" => ["name" => "From PHP"]];');

        $this->assertSame('From PHP', (new ConfigLoader())->load($this->projectDir)->getName());
    }

    public static function provideTargetsAndModes(): iterable
    {
        foreach (['macos-arm64', 'linux-x86_64', 'windows-x64'] as $target) {
            foreach (['embedded', 'custom', 'system'] as $mode) {
                yield $mode.' on '.$target => [$target, $mode];
            }
        }
        foreach (['ios-arm64', 'ios-simulator-arm64', 'android-arm64'] as $target) {
            yield 'embedded on '.$target => [$target, 'embedded'];
        }
        yield 'elephc on macos-arm64' => ['macos-arm64', 'elephc'];
    }

    // opis/string, which validates the schema, triggers deprecations of its own
    #[DataProvider('provideTargetsAndModes')]
    #[IgnoreDeprecations]
    public function testManifestFollowsTheSchema(string $target, string $mode)
    {
        if (!class_exists(Validator::class)) {
            $this->markTestSkipped('The "opis/json-schema" package is required.');
        }

        $config = (new ConfigLoader())->process([
            'app' => ['name' => 'Acme Notes', 'file_associations' => [['extension' => 'acme', 'name' => 'Note']], 'url_schemes' => ['acme']],
            'runtime' => ['mode' => $mode, 'php' => ['path' => 'custom' === $mode ? '/opt/php' : null], 'env' => ['set' => ['APP_ENV' => 'prod']]],
            'build' => ['targets' => [$target]],
        ], $this->projectDir);

        $manifest = json_decode(json_encode($config->toManifest($target)));
        $result = (new Validator())->validate($manifest, file_get_contents(__DIR__.'/../../Resources/contract/manifest.schema.json'));

        $this->assertTrue($result->isValid(), json_encode(null === $result->error() ? [] : (new ErrorFormatter())->format($result->error())));
    }

    public function testEmbeddedOnWindowsRunsPhpExe()
    {
        $manifest = (new ConfigLoader())->process([], $this->projectDir)->toManifest('windows-x64');

        $this->assertSame('runtime/php.exe', $manifest['launch']['command']);
        $this->assertSame(['-n', './app/bin/app.php'], $manifest['launch']['args']);
    }

    public function testElephcRunsTheExecutable()
    {
        $manifest = (new ConfigLoader())->process(['app' => ['name' => 'Acme Notes', 'args' => ['--fast']], 'runtime' => ['mode' => 'elephc'], 'build' => ['targets' => ['linux-arm64']]], $this->projectDir)->toManifest('linux-arm64');

        $this->assertSame(['app/acme-notes', ['--fast']], [$manifest['launch']['command'], $manifest['launch']['args']]);
    }

    public function testElephcDoesNotBuildForWindows()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The elephc runtime builds for macos-arm64, linux-x86_64, linux-arm64 only, not for windows-x64.');

        (new ConfigLoader())->process(['runtime' => ['mode' => 'elephc'], 'build' => ['targets' => ['windows-x64']]], $this->projectDir);
    }

    public function testPathsAreNormalized()
    {
        $config = (new ConfigLoader())->process(['app' => ['entry' => '.\\bin//./app.php'], 'build' => ['include' => ['./src/', '.']]], $this->projectDir)->toArray();

        $this->assertSame('bin/app.php', $config['app']['entry']);
        $this->assertSame(['src', '.'], $config['build']['include']);
    }

    #[DataProvider('provideOutsidePaths')]
    public function testPathsStayInTheProject(string $path)
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be a path inside the project directory');

        (new ConfigLoader())->process(['build' => ['include' => [$path]]], $this->projectDir);
    }

    public static function provideOutsidePaths(): iterable
    {
        yield ['../shared'];
        yield ['src/../../shared'];
        yield ['/etc'];
        yield ['C:\\Windows'];
    }

    public function testCustomRuntimeNeedsAPhpBinary()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('runtime.php.path');

        (new ConfigLoader())->process(['runtime' => ['mode' => 'custom']], $this->projectDir);
    }

    public function testInvalidId()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('is not a reverse-DNS identifier');

        (new ConfigLoader())->process(['app' => ['id' => 'notes']], $this->projectDir);
    }

    public function testIconMustBeAPngOrAnSvg()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be a PNG or an SVG file');

        (new ConfigLoader())->process(['app' => ['icon' => 'icon.jpg']], $this->projectDir);
    }

    public function testCheckWarnsWithoutAnIcon()
    {
        $warnings = (new ConfigLoader())->process([], $this->projectDir)->check();

        $this->assertStringContainsString('No icon is configured', $warnings[0]);
    }

    public function testCheckRejectsASmallIcon()
    {
        $image = imagecreatetruecolor(256, 256);
        imagepng($image, $this->projectDir.'/icon.png');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is 256×256 pixels; it must be at least 512×512');

        (new ConfigLoader())->process(['app' => ['icon' => 'icon.png']], $this->projectDir)->check();
    }

    public function testCheckNeedsTheEntryScript()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The entry script');

        (new ConfigLoader())->process(['app' => ['entry' => 'bin/missing.php']], $this->projectDir)->check();
    }

    public function testExcludesAddToTheDefaults()
    {
        $exclude = (new ConfigLoader())->process(['build' => ['exclude' => ['*.log']]], $this->projectDir)->toArray()['build']['exclude'];

        $this->assertContains('*.log', $exclude);
        $this->assertContains('tests', $exclude);
        $this->assertContains('.env.local', $exclude);
    }

    public function testDefaultExcludesCanBeTurnedOff()
    {
        $exclude = (new ConfigLoader())->process(['build' => ['exclude' => ['*.log'], 'exclude_defaults' => false]], $this->projectDir)->toArray()['build']['exclude'];

        $this->assertSame(['*.log'], $exclude);
    }

    #[DataProvider('provideOutsideOutputs')]
    public function testTheOutputStaysInTheProject(string $output)
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be a path inside the project directory');

        (new ConfigLoader())->process(['build' => ['output' => $output]], $this->projectDir);
    }

    public static function provideOutsideOutputs(): iterable
    {
        yield ['../out'];
        yield ['/tmp/out'];
        yield ['.'];
    }

    public function testTheOutputInfoSaysWhatTheBuildDoes()
    {
        $output = (new Configuration())->getConfigTreeBuilder()->buildTree()->getChildren()['build']->getChildren()['output'];

        $this->assertStringNotContainsString('emptied', $output->getInfo());
        $this->assertStringContainsString('one directory per target', $output->getInfo());
    }

    public function testExternalHostPathsAreRejected()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unrecognized option "path"');
        (new ConfigLoader())->process(['build' => ['hosts' => ['path' => '/external/hosts']]], $this->projectDir);
    }

    public function testHostsAreBundled()
    {
        $config = (new ConfigLoader())->process([], $this->projectDir);
        $this->assertSame(\dirname(__DIR__, 2).'/Resources', $config->getHostsDir());
        $this->assertFileExists($config->getHostsDir().'/macos/build-app.sh');
    }

    #[DataProvider('provideInvalidValues')]
    public function testInvalidValues(array $config, string $message)
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new ConfigLoader())->process($config, $this->projectDir);
    }

    public static function provideInvalidValues(): iterable
    {
        yield 'array argument' => [['app' => ['args' => [['--port']]]], 'app.args'];
        yield 'boolean passed variable' => [['runtime' => ['env' => ['pass' => [true]]]], 'runtime.env.pass'];
        yield 'URL scheme' => [['app' => ['url_schemes' => ['Acme Notes']]], 'is not a URL scheme'];
        yield 'extension' => [['app' => ['file_associations' => [['extension' => 'a/b', 'name' => 'Note']]]], 'is not a file extension'];
        yield 'PHP version' => [['runtime' => ['php' => ['version' => 'latest']]], 'is not a PHP version'];
        yield 'old PHP version' => [['runtime' => ['php' => ['version' => '8.3']]], 'PHP 8.4 or later'];
        yield 'old PHP min version' => [['runtime' => ['php' => ['min_version' => '7.4']]], 'PHP 8.4 or later'];
        yield 'app version' => [['app' => ['version' => 'one']], 'is not a version'];
        yield 'duplicate targets' => [['build' => ['targets' => ['linux-x86_64', 'linux-x86_64']]], 'more than once'];
        yield 'min width' => [['window' => ['width' => 800, 'min_width' => 900]], '"window.min_width" (900) is larger than "window.width" (800)'];
        yield 'min height' => [['window' => ['height' => 600, 'min_height' => 700]], '"window.min_height" (700) is larger than "window.height" (600)'];
        yield 'extension name' => [['runtime' => ['php' => ['extensions' => ['Zend OPcache']]]], 'is not an extension name'];
        yield 'notarize without identity' => [['signing' => ['macos' => ['notarize' => true, 'keychain_profile' => 'acme']]], 'Notarizing needs "signing.macos.identity"'];
        yield 'notarize without profile' => [['signing' => ['macos' => ['notarize' => true, 'identity' => 'Developer ID Application: Acme']]], 'Notarizing needs "signing.macos.keychain_profile"'];
        yield 'team id' => [['signing' => ['macos' => ['team_id' => 'ABCDE12345']]], 'Unrecognized option "team_id"'];
        yield 'entitlements' => [['signing' => ['macos' => ['entitlements' => 'app.entitlements']]], 'Unrecognized option "entitlements"'];
        yield 'elephc entry' => [['app' => ['entry' => 'bin/app'], 'runtime' => ['mode' => 'elephc'], 'build' => ['targets' => ['linux-x86_64']]], 'must end with ".php"'];
        yield 'elephc constraint' => [['runtime' => ['elephc' => ['version' => '0.27.*']]], 'is not a version constraint'];
    }

    public function testBooleansAreStrings()
    {
        $config = (new ConfigLoader())->process(['app' => ['args' => ['--verbose', true]], 'runtime' => ['env' => ['set' => ['APP_DEBUG' => false]], 'elephc' => ['flags' => [true, false]]]], $this->projectDir)->toArray();

        $this->assertSame(['--verbose', '1'], $config['app']['args']);
        $this->assertSame(['APP_DEBUG' => '0'], $config['runtime']['env']['set']);
        $this->assertSame(['1', '0'], $config['runtime']['elephc']['flags']);
    }

    public function testNumbersAreStrings()
    {
        $config = (new ConfigLoader())->process(['app' => ['args' => ['--port', 8080]], 'runtime' => ['env' => ['set' => ['APP_DEBUG' => 0]], 'php' => ['ini' => ['opcache.enable' => true, 'memory_limit' => 256]]]], $this->projectDir);

        $this->assertSame(['--port', '8080'], $config->toArray()['app']['args']);
        $this->assertSame(['APP_DEBUG' => '0'], $config->toArray()['runtime']['env']['set']);
        $this->assertSame(['opcache.enable' => '1', 'memory_limit' => '256'], $config->toArray()['runtime']['php']['ini']);
    }

    public function testExtensionsLoseTheirDots()
    {
        $manifest = (new ConfigLoader())->process(['app' => ['file_associations' => [['extension' => '.acme', 'name' => 'Note'], ['extension' => 'tar.gz', 'name' => 'Archive']]]], $this->projectDir)->toManifest('linux-x86_64');

        $this->assertSame(['acme', 'tar.gz'], array_column($manifest['app']['fileAssociations'], 'extension'));
    }

    #[DataProvider('provideDerivedNames')]
    public function testDerivedIdAndExecutableName(string $name, string $id, string $executable)
    {
        $config = (new ConfigLoader())->process(['app' => ['name' => $name]], $this->projectDir.'/none');

        $this->assertSame($id, $config->getId());
        $this->assertSame($executable, $config->getExecutableName());
    }

    public static function provideDerivedNames(): iterable
    {
        yield ['Notes', 'app.notes', 'notes'];
        yield ['Café Notes', 'cafe.notes', 'cafe-notes'];
        yield ['--Notes--', 'app.notes', 'notes'];
        yield ['日本', 'app.ri-ben', 'ri-ben'];
        yield ['!!!', 'app.app', 'app'];
    }
}
