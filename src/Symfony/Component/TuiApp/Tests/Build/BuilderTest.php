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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\TuiApp\Build\Builder;
use Symfony\Component\TuiApp\Build\ElephcException;
use Symfony\Component\TuiApp\Command\BuildCommand;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Config\ConfigLoader;
use Symfony\Component\TuiApp\Config\Configuration;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;
use Symfony\Component\TuiApp\Exception\RuntimeException;

/**
 * The hosts are stand-in scripts that record what they are given.
 */
#[RequiresOperatingSystemFamily('Darwin')]
class BuilderTest extends TestCase
{
    private string $dir;
    private string $projectDir;
    private string $hosts;

    protected function setUp(): void
    {
        if ('macos' !== explode('-', AppConfig::currentTarget())[0]) {
            $this->markTestSkipped('The stand-in hosts package for macOS.');
        }

        $this->dir = sys_get_temp_dir().'/tui-app-build-'.bin2hex(random_bytes(4));
        $this->projectDir = $this->dir.'/project';
        $this->hosts = $this->dir.'/hosts';
        $fs = new Filesystem();
        $fs->dumpFile($this->projectDir.'/bin/app.php', '<?php echo "app";');
        $fs->dumpFile($this->projectDir.'/src/Notes.php', '<?php');
        $fs->dumpFile($this->projectDir.'/src/Tests/NotesTest.php', '<?php');
        $fs->dumpFile($this->projectDir.'/vendor/autoload.php', '<?php');
        $fs->dumpFile($this->projectDir.'/README.md', 'readme');
        $fs->dumpFile($this->projectDir.'/composer.json', '{"name": "acme/notes"}');
        $fs->dumpFile($this->hosts.'/contract/protocol.json', '{}');
        $fs->dumpFile($this->hosts.'/macos/build-app.sh', <<<'SH'
            #!/bin/sh
            # Records its arguments, copies what a bundle holds and prints its path
            printf '%s\n' "$@" > "$(dirname "$0")/args.txt"
            while [ $# -gt 3 ]; do shift; done
            name=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["app"]["name"];' "$1")
            mkdir -p "$3/$name.app/Contents/Resources"
            cp "$1" "$3/$name.app/Contents/Resources/app.manifest.json"
            cp -R "$2/." "$3/$name.app/Contents/Resources/"
            echo "built" >&2
            echo "$3/$name.app"
            SH);
        $fs->chmod($this->hosts.'/macos/build-app.sh', 0o755);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            (new Filesystem())->remove($this->dir);
        }
    }

    public function testBuildsAnAppBundleWithTheSystemPhp()
    {
        $config = $this->config(['runtime' => ['mode' => 'system'], 'build' => ['formats' => ['macos' => ['app']]]]);
        $log = [];

        $packages = (new Builder(new LogRecorder(static function (string $message) use (&$log): void { $log[] = $message; }), $this->runHostCommand(...)))->build($config);

        $app = $this->projectDir.'/build/app/'.AppConfig::currentTarget().'/Notes.app';
        $this->assertSame([$app], $packages);
        $resources = $app.'/Contents/Resources';
        $this->assertFileExists($resources.'/app/bin/app.php');
        $this->assertFileExists($resources.'/app/src/Notes.php');
        $this->assertFileExists($resources.'/app/vendor/autoload.php');
        $this->assertFileDoesNotExist($resources.'/app/src/Tests/NotesTest.php', 'Excluded');
        $this->assertFileDoesNotExist($resources.'/app/README.md', 'Not included');
        $this->assertDirectoryDoesNotExist($resources.'/runtime');
        $manifest = json_decode(file_get_contents($resources.'/app.manifest.json'), true);
        $this->assertSame(['mode' => 'system', 'command' => null, 'args' => ['./app/bin/app.php'], 'php' => ['path' => null, 'minVersion' => '8.4.1'], 'env' => ['pass' => [], 'set' => []]], $manifest['launch']);
        $this->assertContains('  built', $log, 'What the scripts print on stderr is logged');
        $this->assertStringContainsString('No icon is configured', $log[0]);
    }

    public function testSecretsAreNotShipped()
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->projectDir.'/config/services.yaml', 'services:');
        $fs->dumpFile($this->projectDir.'/config/secrets/prod/prod.decrypt.private.php', '<?php return "key";');
        $fs->dumpFile($this->projectDir.'/.env.local', 'APP_SECRET=s');
        $config = $this->config(['runtime' => ['mode' => 'system'], 'build' => ['include' => ['bin', 'config', '.env.local'], 'formats' => ['macos' => ['app']]]]);

        [$app] = (new Builder(null, $this->runHostCommand(...)))->build($config);

        $this->assertFileExists($app.'/Contents/Resources/app/config/services.yaml');
        $this->assertFileDoesNotExist($app.'/Contents/Resources/app/config/secrets/prod/prod.decrypt.private.php');
        $this->assertFileDoesNotExist($app.'/Contents/Resources/app/.env.local', 'Excluded even when included by name');
    }

    public function testADecryptionKeyStopsTheBuild()
    {
        (new Filesystem())->dumpFile($this->projectDir.'/config/secrets/prod/prod.decrypt.private.php', '<?php return "key";');
        $config = $this->config(['runtime' => ['mode' => 'system'], 'build' => ['include' => ['bin', 'config'], 'exclude' => [], 'exclude_defaults' => false]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('it must never be shipped');

        (new Builder(null, $this->runHostCommand(...)))->build($config);
    }

    public function testALinkToADecryptionKeyStopsTheBuild()
    {
        (new Filesystem())->dumpFile($this->projectDir.'/config/secrets/prod/prod.decrypt.private.php', '<?php return "key";');
        symlink($this->projectDir.'/config/secrets/prod/prod.decrypt.private.php', $this->projectDir.'/src/key.php');
        $config = $this->config(['runtime' => ['mode' => 'system'], 'build' => ['include' => ['bin', 'src']]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('links to a key that decrypts the secrets');

        (new Builder(null, $this->runHostCommand(...)))->build($config);
    }

    public function testALinkToAnExcludedFileIsNotShipped()
    {
        (new Filesystem())->dumpFile($this->projectDir.'/.env.local', 'APP_SECRET=s');
        symlink($this->projectDir.'/.env.local', $this->projectDir.'/src/env.txt');
        $config = $this->config(['runtime' => ['mode' => 'system'], 'build' => ['include' => ['bin', 'src'], 'formats' => ['macos' => ['app']]]]);

        [$app] = (new Builder(null, $this->runHostCommand(...)))->build($config);

        $this->assertFileDoesNotExist($app.'/Contents/Resources/app/src/env.txt');
    }

    public function testLinksOutOfTheProjectAreNotFollowed()
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->dir.'/outside/secret.txt', 'secret');
        $fs->dumpFile($this->projectDir.'/src/inside.txt', 'inside');
        symlink($this->dir.'/outside', $this->projectDir.'/src/outside');
        symlink($this->projectDir.'/src/inside.txt', $this->projectDir.'/src/alias.txt');
        $config = $this->config(['runtime' => ['mode' => 'system'], 'build' => ['include' => ['bin', 'src'], 'formats' => ['macos' => ['app']]]]);
        $log = [];

        [$app] = (new Builder(new LogRecorder(static function (string $message) use (&$log): void { $log[] = $message; }), $this->runHostCommand(...)))->build($config);

        $this->assertFileDoesNotExist($app.'/Contents/Resources/app/src/outside/secret.txt');
        $this->assertSame('inside', file_get_contents($app.'/Contents/Resources/app/src/alias.txt'));
        $this->assertNotEmpty(array_filter($log, static fn (string $line): bool => str_contains($line, 'links out of the project')));
    }

    public function testTheProjectCanBeIncludedWithoutItsBuilds()
    {
        $config = $this->config(['runtime' => ['mode' => 'system'], 'build' => ['include' => ['./'], 'formats' => ['macos' => ['app']]]]);

        (new Builder(null, $this->runHostCommand(...)))->build($config);
        [$app] = (new Builder(null, $this->runHostCommand(...)))->build($config);

        $this->assertFileExists($app.'/Contents/Resources/app/bin/app.php');
        $this->assertFileExists($app.'/Contents/Resources/app/src/Notes.php');
        $this->assertDirectoryDoesNotExist($app.'/Contents/Resources/app/build/app');
    }

    public function testLinkLoopsAreNotFollowed()
    {
        $fs = new Filesystem();
        $fs->mkdir([$this->projectDir.'/vendor/acme/a', $this->projectDir.'/vendor/acme/b']);
        symlink('../../..', $this->projectDir.'/vendor/acme/a/project');
        symlink('../b', $this->projectDir.'/vendor/acme/a/b');
        symlink('../a', $this->projectDir.'/vendor/acme/b/a');
        $config = $this->config(['runtime' => ['mode' => 'system'], 'build' => ['include' => ['bin', 'vendor'], 'formats' => ['macos' => ['app']]]]);
        $log = [];

        [$app] = (new Builder(new LogRecorder(static function (string $message) use (&$log): void { $log[] = $message; }), $this->runHostCommand(...)))->build($config);

        $this->assertFileExists($app.'/Contents/Resources/app/vendor/autoload.php');
        $this->assertDirectoryDoesNotExist($app.'/Contents/Resources/app/vendor/acme/a/project');
        $this->assertDirectoryExists($app.'/Contents/Resources/app/vendor/acme/a/b');
        $this->assertDirectoryDoesNotExist($app.'/Contents/Resources/app/vendor/acme/a/b/a');
        $this->assertDirectoryDoesNotExist($app.'/Contents/Resources/app/vendor/acme/b/a/b');
        $refused = array_map(static fn (string $line): string => explode('"', $line)[1], preg_grep('/links to a folder it is in/', $log));
        sort($refused);
        $this->assertSame(['vendor/acme/a/b/a', 'vendor/acme/a/project', 'vendor/acme/b/a/b', 'vendor/acme/b/a/project'], $refused);
    }

    public function testExcludesAreWrittenFromTheProject()
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->projectDir.'/config/services.yaml', 'services:');
        $fs->dumpFile($this->projectDir.'/config/secrets/prod/prod.encrypt.public.php', '<?php');
        $config = $this->config(['runtime' => ['mode' => 'system'], 'build' => ['include' => ['bin', 'config'], 'exclude' => ['config/secrets'], 'formats' => ['macos' => ['app']]]]);

        [$app] = (new Builder(null, $this->runHostCommand(...)))->build($config);

        $this->assertFileExists($app.'/Contents/Resources/app/config/services.yaml');
        $this->assertDirectoryDoesNotExist($app.'/Contents/Resources/app/config/secrets');
    }

    public function testExcludesApplyThroughALinkToAFolderAbove()
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->projectDir.'/config/services.yaml', 'services:');
        $fs->dumpFile($this->projectDir.'/config/secrets/prod/prod.encrypt.public.php', '<?php');
        symlink('../config', $this->projectDir.'/src/conf');
        $config = $this->config(['runtime' => ['mode' => 'system'], 'build' => ['include' => ['bin', 'src'], 'exclude' => ['config/secrets'], 'formats' => ['macos' => ['app']]]]);

        [$app] = (new Builder(null, $this->runHostCommand(...)))->build($config);

        $this->assertFileExists($app.'/Contents/Resources/app/src/conf/services.yaml');
        $this->assertDirectoryDoesNotExist($app.'/Contents/Resources/app/src/conf/secrets');
    }

    public function testAnIncludedLinkToADecryptionKeyStopsTheBuild()
    {
        (new Filesystem())->dumpFile($this->projectDir.'/config/secrets/prod/prod.decrypt.private.php', '<?php return "key";');
        symlink($this->projectDir.'/config/secrets/prod/prod.decrypt.private.php', $this->projectDir.'/app.key.php');
        $config = $this->config(['runtime' => ['mode' => 'system'], 'build' => ['include' => ['bin', 'app.key.php']]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('links to a key that decrypts the secrets');

        (new Builder(null, $this->runHostCommand(...)))->build($config);
    }

    public function testAnEntryInAHiddenFolder()
    {
        (new Filesystem())->dumpFile($this->projectDir.'/.tui/main.php', '<?php');
        $config = $this->config(['app' => ['entry' => './.tui/main.php'], 'runtime' => ['mode' => 'system'], 'build' => ['include' => ['.tui'], 'formats' => ['macos' => ['app']]]]);

        [$app] = (new Builder(null, $this->runHostCommand(...)))->build($config);

        $this->assertFileExists($app.'/Contents/Resources/app/.tui/main.php');
        $manifest = json_decode(file_get_contents($app.'/Contents/Resources/app.manifest.json'), true);
        $this->assertContains('./app/.tui/main.php', $manifest['launch']['args']);
    }

    public function testIconAndSigningGoToTheScript()
    {
        $image = imagecreatetruecolor(1024, 1024);
        imagepng($image, $this->projectDir.'/icon.png');
        $config = $this->config(['app' => ['icon' => 'icon.png'], 'runtime' => ['mode' => 'system'], 'signing' => ['macos' => ['identity' => 'Developer ID Application: Acme']], 'build' => ['formats' => ['macos' => ['app']]]]);

        (new Builder(null, $this->runHostCommand(...)))->build($config);

        $args = file($this->hosts.'/macos/args.txt', \FILE_IGNORE_NEW_LINES);
        $this->assertSame(['--icon', $this->projectDir.'/icon.png', '--sign', 'Developer ID Application: Acme'], \array_slice($args, 0, 4));
    }

    public function testEmbeddedRuntimeSaysWhereItsPhpComesFrom()
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->hosts.'/runtime/build-php.sh', <<<'SH'
            #!/bin/sh
            # Writes a PHP binary where --out says, as a cache hit would
            while [ $# -gt 0 ]; do [ "$1" = --out ] && out=$2; shift; done
            printf '#!/bin/sh\n' > "$out"
            echo "build-php: PHP 8.5.13 from the cache (/cache/abc) -> $out"
            SH);
        $fs->chmod($this->hosts.'/runtime/build-php.sh', 0o755);
        $config = $this->config(['runtime' => ['mode' => 'embedded'], 'build' => ['formats' => ['macos' => ['app']]]]);
        $log = [];

        [$app] = (new Builder(new LogRecorder(static function (string $message) use (&$log): void { $log[] = $message; }), $this->runHostCommand(...)))->build($config);

        $this->assertFileExists($app.'/Contents/Resources/runtime/php');
        $this->assertNotEmpty(preg_grep('{^\[macos-arm64\] PHP 8\.5\.13 from the cache \(/cache/abc\)}', $log), implode("\n", $log));
    }

    public function testEmbeddedRuntimeAddsTheExtensionsThePackagesRequire()
    {
        $this->recordBuildPhp();
        (new Filesystem())->dumpFile($this->hosts.'/runtime/static-extensions.list', "# <extension> <platforms>\ndom linux,macos,windows\nopcache linux,macos,windows\nsqlite3 linux,macos,windows\ncom_dotnet windows\n");
        $this->writeComposer(['name' => 'acme/notes', 'require' => ['php' => '>=8.4', 'ext-SQLite3' => '*', 'ext-json' => '*', 'ext-uri' => '*', 'ext-mongodb' => '*'], 'require-dev' => ['ext-xdebug' => '*'], 'provide' => ['ext-mongodb' => '*']], [
            ['name' => 'symfony/tui-html', 'require' => ['ext-dom' => '*', 'ext-mbstring' => '*', 'ext-phar' => '*'], 'suggest' => ['ext-gd' => 'images']],
            ['name' => 'acme/cache', 'require' => ['ext-zend-opcache' => '*', 'ext-dom' => '*']],
            ['name' => 'acme/windows', 'require' => ['ext-com_dotnet' => '*']],
            ['name' => 'phpunit/phpunit', 'require' => ['ext-xmlwriter' => '*']],
        ], ['phpunit/phpunit']);
        $log = [];

        (new Builder(new LogRecorder(static function (string $message) use (&$log): void { $log[] = $message; }), $this->runHostCommand(...)))->build($this->config(['runtime' => ['mode' => 'embedded'], 'build' => ['formats' => ['macos' => ['app']]]]));

        $this->assertSame([...Configuration::DEFAULT_EXTENSIONS, 'dom', 'sqlite3'], $this->builtExtensions(), 'Not json, phar, uri or opcache, which PHP 8.5 has, mbstring, configured, mongodb, which the project provides, nor those of require-dev, dev packages or suggest');
        $this->assertContains('[macos-arm64] adding dom, required by symfony/tui-html, acme/cache; sqlite3, required by acme/notes', $log);
        $this->assertContains('[macos-arm64] warning: com_dotnet, required by acme/windows, is not built on macos', $log, 'Left out where static-php-cli cannot build it');
    }

    public function testEmbeddedRuntimeReadsTheVendorDirectoryOfTheProject()
    {
        $this->recordBuildPhp();
        $fs = new Filesystem();
        $fs->dumpFile($this->projectDir.'/composer.json', json_encode(['name' => 'acme/notes', 'config' => ['vendor-dir' => 'lib']]));
        $fs->dumpFile($this->projectDir.'/lib/composer/installed.json', json_encode(['packages' => [['name' => 'symfony/tui-html', 'require' => ['ext-dom' => '*']]]]));
        $log = [];

        (new Builder(new LogRecorder(static function (string $message) use (&$log): void { $log[] = $message; }), $this->runHostCommand(...)))->build($this->config(['runtime' => ['mode' => 'embedded'], 'build' => ['formats' => ['macos' => ['app']]]]));

        $this->assertContains('dom', $this->builtExtensions());

        $fs->remove($this->projectDir.'/lib');
        $log = [];
        (new Builder(new LogRecorder(static function (string $message) use (&$log): void { $log[] = $message; }), $this->runHostCommand(...)))->build($this->config(['runtime' => ['mode' => 'embedded'], 'build' => ['formats' => ['macos' => ['app']]]]));
        $this->assertNotEmpty(preg_grep('{^\[macos-arm64\] warning: no .*/lib/composer/installed\.json, the extensions the packages require are not looked for$}', $log), implode("\n", $log));
    }

    public function testSystemRuntimeSaysTheExtensionsThePhpOfTheComputerMustHave()
    {
        $this->writeComposer(['name' => 'acme/notes'], [['name' => 'symfony/tui-html', 'require' => ['ext-dom' => '*']]]);
        $log = [];

        (new Builder(new LogRecorder(static function (string $message) use (&$log): void { $log[] = $message; }), $this->runHostCommand(...)))->build($this->config(['runtime' => ['mode' => 'system'], 'build' => ['formats' => ['macos' => ['app']]]]));

        $this->assertContains('[macos-arm64] the PHP of the computer must have dom, required by symfony/tui-html', $log);
        $this->assertEmpty(preg_grep('{adding}', $log));
    }

    private function recordBuildPhp(): void
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->hosts.'/runtime/build-php.sh', <<<'SH'
            #!/bin/sh
            # Records its arguments and writes a PHP binary where --out says
            printf '%s\n' "$@" > "$(dirname "$0")/args.txt"
            while [ $# -gt 0 ]; do [ "$1" = --out ] && out=$2; shift; done
            printf '#!/bin/sh\n' > "$out"
            SH);
        $fs->chmod($this->hosts.'/runtime/build-php.sh', 0o755);
    }

    /**
     * @return list<string>
     */
    private function builtExtensions(): array
    {
        $args = file($this->hosts.'/runtime/args.txt', \FILE_IGNORE_NEW_LINES);

        return explode(',', $args[array_search('--extensions', $args, true) + 1]);
    }

    private function writeComposer(array $root, array $packages, array $dev = []): void
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->projectDir.'/composer.json', json_encode($root));
        $fs->dumpFile($this->projectDir.'/vendor/composer/installed.json', json_encode(['packages' => $packages, 'dev-package-names' => $dev]));
    }

    public function testEmbeddedRuntimeLogsItsProgressAsItGoes()
    {
        $fs = new Filesystem();
        $flag = $this->dir.'/logged';
        $fs->dumpFile($this->hosts.'/runtime/build-php.sh', <<<SH
            #!/bin/sh
            # Goes on once the builder logged its first line, like a long build the user follows
            while [ \$# -gt 0 ]; do [ "\$1" = --out ] && out=\$2; shift; done
            echo "build-php: downloading PHP 8.5.13"
            i=0
            while [ ! -f '{$flag}' ] && [ \$i -lt 100 ]; do sleep 0.05; i=\$((i+1)); done
            printf '#!/bin/sh\\n' > "\$out"
            [ -f '{$flag}' ] && echo "build-php: built after the first line was logged" || echo "build-php: the first line was not logged in time"
            SH);
        $fs->chmod($this->hosts.'/runtime/build-php.sh', 0o755);
        $config = $this->config(['runtime' => ['mode' => 'embedded'], 'build' => ['formats' => ['macos' => ['app']]]]);
        $log = [];

        (new Builder(new LogRecorder(static function (string $message) use (&$log, $fs, $flag): void {
            $log[] = $message;
            if (str_contains($message, 'downloading PHP')) {
                $fs->touch($flag);
            }
        }), $this->runHostCommand(...)))->build($config);

        $this->assertNotEmpty(preg_grep('{^\[macos-arm64\] built after the first line was logged$}', $log), implode("\n", $log));
    }

    public function testCustomRuntimeShipsAndChecksTheBinary()
    {
        $config = $this->config(['runtime' => ['mode' => 'custom', 'php' => ['path' => $this->staticPhp(), 'extensions' => ['ctype']]], 'build' => ['formats' => ['macos' => ['app']]]]);

        [$app] = (new Builder(null, $this->runHostCommand(...)))->build($config);

        $this->assertFileExists($app.'/Contents/Resources/runtime/php');
        $this->assertSame('runtime/php', json_decode(file_get_contents($app.'/Contents/Resources/app.manifest.json'), true)['launch']['command']);
    }

    public function testCustomRuntimeWithoutAnExtension()
    {
        $config = $this->config(['runtime' => ['mode' => 'custom', 'php' => ['path' => $this->staticPhp(), 'extensions' => ['ctype', 'not_an_extension']]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The PHP binary lacks the extension not_an_extension ("runtime.php.extensions").');

        (new Builder(null, $this->runHostCommand(...)))->build($config);
    }

    public function testCustomRuntimeWithoutAnExtensionAPackageRequires()
    {
        $this->writeComposer(['name' => 'acme/notes'], [['name' => 'symfony/tui-html', 'require' => ['ext-dom' => '*']]]);
        $config = $this->config(['runtime' => ['mode' => 'custom', 'php' => ['path' => $this->staticPhp(), 'extensions' => ['ctype', 'not_an_extension']]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The PHP binary lacks the extensions not_an_extension ("runtime.php.extensions"); dom, required by symfony/tui-html.');

        (new Builder(null, $this->runHostCommand(...)))->build($config);
    }

    public function testCustomRuntimeTooOld()
    {
        $config = $this->config(['runtime' => ['mode' => 'custom', 'php' => ['path' => $this->staticPhp('8.3.9'), 'extensions' => []]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The PHP binary is 8.3.9; the application needs 8.4.1 or later');

        (new Builder(null, $this->runHostCommand(...)))->build($config);
    }

    public function testElephcProblemsAreListed()
    {
        $elephc = $this->dir.'/elephc';
        file_put_contents($elephc, "#!/bin/sh\nif [ \"\$1\" = --version ]; then echo 'elephc 0.27.8'; exit 0; fi\nif [ \"\$1\" = native ]; then exit 0; fi\ncat ".escapeshellarg(__DIR__.'/../Fixtures/elephc-output.txt')." >&2\nexit 1\n");
        chmod($elephc, 0o755);
        $config = $this->config(['runtime' => ['mode' => 'elephc', 'elephc' => ['binary' => $elephc]], 'build' => ['targets' => ['macos-arm64']]]);

        try {
            (new Builder(null, $this->runHostCommand(...)))->build($config);
            $this->fail('elephc failed');
        } catch (ElephcException $e) {
        }

        $this->assertCount(40, $e->getProblems());
        $this->assertSame(['file' => '/build/app/vendor/symfony/event-dispatcher-contracts/EventDispatcherInterface.php', 'line' => 32, 'message' => 'Cannot change parameter count when redeclaring interface method: Symfony\Contracts\EventDispatcher\EventDispatcherInterface::dispatch'], $e->getProblems()[0]);
        $this->assertSame(['file' => '(application)', 'line' => null, 'message' => 'Circular interface inheritance detected involving Symfony\Contracts\EventDispatcher\EventDispatcherInterface'], $e->getProblems()[1]);
        $this->assertStringContainsString('reported 40 problems', $e->getMessage());
        $this->assertStringContainsString('and 10 more', $e->getMessage());
    }

    public function testElephcVersionMustMatch()
    {
        $elephc = $this->dir.'/elephc';
        file_put_contents($elephc, "#!/bin/sh\necho 'elephc 0.26.1'\n");
        chmod($elephc, 0o755);
        $config = $this->config(['runtime' => ['mode' => 'elephc', 'elephc' => ['binary' => $elephc]], 'build' => ['targets' => ['macos-arm64']]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('elephc 0.26.1 does not match "runtime.elephc.version" (^0.27)');

        (new Builder(null, $this->runHostCommand(...)))->build($config);
    }

    public function testLinuxBuildsInItsRunner()
    {
        (new Filesystem())->dumpFile($this->hosts.'/linux/package.sh', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"\$(dirname \"\$0\")/args.txt\"\nwhile [ \$# -gt 3 ]; do shift; done\ntouch \"\$3/notes.tar.gz\"\necho \"\$3/notes.tar.gz\"\n");
        chmod($this->hosts.'/linux/package.sh', 0o755);
        $runner = $this->dir.'/runner';
        file_put_contents($runner, "#!/bin/sh\necho \"\$@\" > ".escapeshellarg($this->dir.'/runner.txt')."\nexec \"\$@\"\n");
        chmod($runner, 0o755);
        $config = $this->config(['runtime' => ['mode' => 'system'], 'build' => ['targets' => ['linux-x86_64'], 'formats' => ['linux' => ['tar']], 'hosts' => ['linux_runners' => ['x86_64' => [$runner]]]]]);

        $packages = (new Builder(null, $this->runHostCommand(...)))->build($config);

        $this->assertSame([$this->projectDir.'/build/app/linux-x86_64/notes.tar.gz'], $packages);
        $this->assertStringStartsWith(realpath($this->hosts).'/linux/package.sh --arch x86_64 --formats tar ', file_get_contents($this->dir.'/runner.txt'));
    }

    public function testLinuxNeedsARunnerElsewhere()
    {
        $config = $this->config(['runtime' => ['mode' => 'system'], 'build' => ['targets' => ['linux-arm64']]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set "build.hosts.linux_runners.arm64"');

        (new Builder(null, $this->runHostCommand(...)))->build($config);
    }

    public function testWindowsBuildsOnWindows()
    {
        $config = $this->config(['runtime' => ['mode' => 'system'], 'build' => ['targets' => ['windows-x64']]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The windows-x64 target builds on Windows only');

        (new Builder(null, $this->runHostCommand(...)))->build($config);
    }

    public function testCommand()
    {
        file_put_contents($this->projectDir.'/tui_app.yaml', "app: { name: Notes }\nruntime: { mode: system }\nbuild: { formats: { macos: [app] } }\n");
        $tester = new CommandTester(new BuildCommand(projectDir: $this->projectDir));

        $tester->execute(['--dump-manifest' => 'macos-arm64']);

        $tester->assertCommandIsSuccessful();
        $this->assertSame('Notes', json_decode($tester->getDisplay(), true)['app']['name']);
    }

    public function testCommandDumpsTheManifest()
    {
        $tester = new CommandTester(new BuildCommand($this->config([])));

        $tester->execute(['--dump-manifest' => 'linux-x86_64']);

        $this->assertSame('Notes', json_decode($tester->getDisplay(), true)['app']['name']);
    }

    public static function provideConstraints(): iterable
    {
        yield ['0.27.8', '^0.27', true];
        yield ['0.28.0', '^0.27', false];
        yield ['1.4.0', '^1.2', true];
        yield ['2.0.0', '^1.2', false];
        yield ['1.2.9', '~1.2.3', true];
        yield ['1.3.0', '~1.2.3', false];
        yield ['1.5.0', '>=1.2', true];
        yield ['0.27.8', '0.27', true];
        yield ['0.27.8', '0.26 || ^0.27', true];
    }

    #[DataProvider('provideConstraints')]
    public function testSatisfies(string $version, string $constraint, bool $expected)
    {
        $this->assertSame($expected, Builder::satisfies($version, $constraint));
    }

    /**
     * A stand-in for a static PHP: copied anywhere, it still runs.
     */
    private function staticPhp(string $version = '8.5.12'): string
    {
        $php = $this->dir.'/php-'.$version;
        file_put_contents($php, "#!/bin/sh\nprintf '%s\\n%s' ".escapeshellarg($version)." 'core,ctype,mbstring'\n");
        chmod($php, 0o755);

        return $php;
    }

    private function runHostCommand(array $command, ?string $cwd, ?float $timeout, \Closure $callback): int
    {
        $resources = \dirname(__DIR__, 2).'/Resources';
        $command = array_map(fn (string $arg): string => str_replace($resources, realpath($this->hosts), $arg), $command);
        $process = new \Symfony\Component\Process\Process($command, $cwd, null, null, $timeout);

        return $process->run($callback);
    }

    private function config(array $config): AppConfig
    {
        $config['app']['name'] ??= 'Notes';

        return (new ConfigLoader())->process($config, $this->projectDir);
    }
}
