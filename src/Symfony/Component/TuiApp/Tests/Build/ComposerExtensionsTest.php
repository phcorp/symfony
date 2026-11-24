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

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\TuiApp\Build\ComposerExtensions;

class ComposerExtensionsTest extends TestCase
{
    private string $dir;
    private array $log = [];

    protected function setUp(): void
    {
        $this->dir = Path::canonicalize(sys_get_temp_dir()).'/tui-app-extensions-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testExtensionsThePackagesRequire()
    {
        $this->writeComposer(['name' => 'acme/notes', 'require' => ['php' => '>=8.4', 'ext-SQLite3' => '*', 'ext-json' => '*', 'ext-uri' => '*', 'ext-mongodb' => '*'], 'require-dev' => ['ext-xdebug' => '*'], 'provide' => ['ext-mongodb' => '*']], [
            ['name' => 'symfony/tui-html', 'require' => ['ext-dom' => '*', 'ext-mbstring' => '*', 'ext-phar' => '*'], 'suggest' => ['ext-gd' => 'images']],
            ['name' => 'acme/cache', 'require' => ['ext-zend-opcache' => '*', 'ext-dom' => '*']],
            ['name' => 'phpunit/phpunit', 'require' => ['ext-xmlwriter' => '*']],
        ], ['phpunit/phpunit']);

        $required = $this->extensions()->required($this->dir, ['mbstring'], '8.5', 'linux-x86_64');

        $this->assertSame(['dom' => ['symfony/tui-html', 'acme/cache'], 'sqlite3' => ['acme/notes']], $required);
        $this->assertSame('dom, required by symfony/tui-html, acme/cache; sqlite3, required by acme/notes', ComposerExtensions::describe($required));
        $this->assertSame([], $this->log);
    }

    public function testOpcacheBeforePhp85()
    {
        $this->writeComposer(['name' => 'acme/notes'], [['name' => 'acme/cache', 'require' => ['ext-zend-opcache' => '*']]]);

        $this->assertSame(['opcache' => ['acme/cache']], $this->extensions()->required($this->dir, [], '8.4.1', 'linux-x86_64'));
    }

    public function testExtensionsAPackageProvidesOrReplaces()
    {
        $this->writeComposer(['name' => 'acme/notes'], [
            ['name' => 'acme/ids', 'require' => ['ext-uuid' => '*', 'ext-ctype' => '*', 'ext-dom' => '*']],
            ['name' => 'acme/polyfill-uuid', 'provide' => ['ext-uuid' => '*']],
            ['name' => 'acme/polyfill-ctype', 'replace' => ['ext-ctype' => '*']],
        ]);

        $this->assertSame(['dom' => ['acme/ids']], $this->extensions()->required($this->dir, [], '8.5', 'linux-x86_64'));
    }

    public function testExtensionsADevPackageProvidesAreRequired()
    {
        $this->writeComposer(['name' => 'acme/notes'], [
            ['name' => 'acme/ids', 'require' => ['ext-uuid' => '*']],
            ['name' => 'acme/polyfill-uuid', 'provide' => ['ext-uuid' => '*']],
        ], ['acme/polyfill-uuid']);

        $this->assertSame(['uuid' => ['acme/ids']], $this->extensions()->required($this->dir, [], '8.5', 'linux-x86_64'));
    }

    public function testExtensionsStaticPhpCliDoesNotBuildAreLeftOut()
    {
        $this->writeComposer(['name' => 'acme/notes'], [['name' => 'acme/windows', 'require' => ['ext-com_dotnet' => '*', 'ext-dom' => '*']]]);
        (new Filesystem())->dumpFile($list = $this->dir.'/static-extensions.list', "# <extension> <platforms>\ndom linux,macos,windows\ncom_dotnet windows\n");

        $this->assertSame(['dom' => ['acme/windows']], $this->extensions()->required($this->dir, [], '8.5', 'linux-arm64', $list));
        $this->assertSame(['[linux-arm64] warning: com_dotnet, required by acme/windows, is not built on linux'], $this->log);
        $this->assertSame(['dom', 'com_dotnet'], ComposerExtensions::staticExtensions($list, 'windows'));
        $this->assertNull(ComposerExtensions::staticExtensions($this->dir.'/none.list', 'windows'));
    }

    public function testTheVendorDirectoryOfTheProject()
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->dir.'/composer.json', json_encode(['name' => 'acme/notes', 'config' => ['vendor-dir' => 'lib']]));
        $fs->dumpFile($this->dir.'/lib/composer/installed.json', json_encode([['name' => 'symfony/tui-html', 'require' => ['ext-dom' => '*']]]));

        $this->assertSame(['dom' => ['symfony/tui-html']], $this->extensions()->required($this->dir, [], '8.5', 'linux-x86_64'));
    }

    public function testARelativeProjectDirectory()
    {
        $this->writeComposer(['name' => 'acme/notes'], [['name' => 'symfony/tui-html', 'require' => ['ext-dom' => '*']]]);
        $cwd = getcwd();
        chdir(\dirname($this->dir));
        try {
            $required = $this->extensions()->required(basename($this->dir), [], '8.5', 'linux-x86_64');
        } finally {
            chdir($cwd);
        }

        $this->assertSame(['dom' => ['symfony/tui-html']], $required);
    }

    public function testWithoutInstalledPackages()
    {
        (new Filesystem())->dumpFile($this->dir.'/composer.json', json_encode(['name' => 'acme/notes', 'require' => ['ext-intl' => '*']]));

        $this->assertSame(['intl' => ['acme/notes']], $this->extensions()->required($this->dir, [], '8.5', 'linux-x86_64'));
        $this->assertSame(['[linux-x86_64] warning: no '.$this->dir.'/vendor/composer/installed.json, the extensions the packages require are not looked for'], $this->log);
    }

    private function extensions(): ComposerExtensions
    {
        return new ComposerExtensions(new LogRecorder(function (string $message): void { $this->log[] = $message; }));
    }

    private function writeComposer(array $root, array $packages, array $dev = []): void
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->dir.'/composer.json', json_encode($root));
        $fs->dumpFile($this->dir.'/vendor/composer/installed.json', json_encode(['packages' => $packages, 'dev-package-names' => $dev]));
    }
}
