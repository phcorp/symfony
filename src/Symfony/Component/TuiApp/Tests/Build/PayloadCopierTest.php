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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\TuiApp\Build\PayloadCopier;
use Symfony\Component\TuiApp\Config\ConfigLoader;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;

/**
 * What is shipped through links: symbolic links everywhere, and on Windows the junctions
 * Composer makes for path repositories, which PHP sees as neither links nor folders.
 */
class PayloadCopierTest extends TestCase
{
    private string $dir;
    private string $projectDir;
    private string $payload;
    /** @var list<string> */
    private array $junctions = [];
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tui-app-payload-'.bin2hex(random_bytes(4));
        $this->projectDir = $this->dir.'/project';
        $this->payload = $this->dir.'/payload';
        $fs = new Filesystem();
        $fs->dumpFile($this->projectDir.'/bin/app.php', '<?php echo "app";');
        $fs->dumpFile($this->projectDir.'/vendor/autoload.php', '<?php');
        $fs->dumpFile($this->projectDir.'/packages/lib/composer.json', '{"name": "acme/lib"}');
        $fs->dumpFile($this->projectDir.'/packages/lib/src/functions.php', '<?php');
        $fs->dumpFile($this->projectDir.'/packages/lib/tests/LibTest.php', '<?php');
    }

    protected function tearDown(): void
    {
        // A junction is removed as a folder, without what it leads to
        foreach (array_reverse($this->junctions) as $junction) {
            @rmdir($junction);
        }
        (new Filesystem())->remove($this->dir);
    }

    public static function provideLinks(): iterable
    {
        yield 'symbolic link' => ['symlink'];
        yield 'junction' => ['junction'];
    }

    #[DataProvider('provideLinks')]
    public function testShipsAPackageOfAPathRepository(string $link)
    {
        $this->link($this->projectDir.'/packages/lib', $this->projectDir.'/vendor/acme/lib', $link);

        $this->copy(['include' => ['bin', 'vendor']]);

        $this->assertFileExists($this->payload.'/vendor/autoload.php');
        $this->assertFileExists($this->payload.'/vendor/acme/lib/composer.json');
        $this->assertFileExists($this->payload.'/vendor/acme/lib/src/functions.php');
        $this->assertFileDoesNotExist($this->payload.'/vendor/acme/lib/tests/LibTest.php', 'Excluded');
        $this->assertDirectoryDoesNotExist($this->payload.'/packages', 'Not included');
        $this->assertSame([], $this->log);
    }

    #[DataProvider('provideLinks')]
    public function testShipsAnIncludedLink(string $link)
    {
        $this->link($this->projectDir.'/packages/lib', $this->projectDir.'/vendor/acme/lib', $link);

        $this->copy(['include' => ['bin', 'vendor/acme/lib']]);

        $this->assertFileExists($this->payload.'/vendor/acme/lib/src/functions.php');
        $this->assertFileDoesNotExist($this->payload.'/vendor/autoload.php');
    }

    #[DataProvider('provideLinks')]
    public function testLinksOutOfTheProjectAreNotFollowed(string $link)
    {
        (new Filesystem())->dumpFile($this->dir.'/outside/secret.txt', 'secret');
        $this->link($this->dir.'/outside', $this->projectDir.'/vendor/acme/outside', $link);

        $this->copy(['include' => ['bin', 'vendor']]);

        $this->assertFileExists($this->payload.'/vendor/autoload.php');
        $this->assertFileDoesNotExist($this->payload.'/vendor/acme/outside/secret.txt');
        $this->assertSame(['warning: "vendor/acme/outside" links out of the project and is not shipped'], $this->log);
    }

    #[DataProvider('provideLinks')]
    public function testLinkLoopsAreNotFollowed(string $link)
    {
        (new Filesystem())->mkdir([$this->projectDir.'/vendor/acme/a', $this->projectDir.'/vendor/acme/b']);
        $this->link($this->projectDir, $this->projectDir.'/vendor/acme/a/project', $link);
        $this->link($this->projectDir.'/vendor/acme/b', $this->projectDir.'/vendor/acme/a/b', $link);
        $this->link($this->projectDir.'/vendor/acme/a', $this->projectDir.'/vendor/acme/b/a', $link);

        $this->copy(['include' => ['bin', 'vendor']]);

        $this->assertFileExists($this->payload.'/vendor/autoload.php');
        $this->assertDirectoryDoesNotExist($this->payload.'/vendor/acme/a/project');
        $this->assertDirectoryExists($this->payload.'/vendor/acme/a/b');
        $this->assertDirectoryDoesNotExist($this->payload.'/vendor/acme/a/b/a');
        $this->assertDirectoryDoesNotExist($this->payload.'/vendor/acme/b/a/b');
        $refused = array_map(static fn (string $line): string => explode('"', $line)[1], preg_grep('/links to a folder it is in/', $this->log));
        sort($refused);
        $this->assertSame(['vendor/acme/a/b/a', 'vendor/acme/a/project', 'vendor/acme/b/a/b', 'vendor/acme/b/a/project'], $refused);
    }

    #[DataProvider('provideLinks')]
    public function testExcludesApplyThroughALink(string $link)
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->projectDir.'/config/services.yaml', 'services:');
        $fs->dumpFile($this->projectDir.'/config/secrets/prod/prod.encrypt.public.php', '<?php');
        $fs->dumpFile($this->projectDir.'/var/cache/data.txt', 'cache');
        $this->link($this->projectDir.'/config', $this->projectDir.'/vendor/acme/conf', $link);
        $this->link($this->projectDir.'/var/cache', $this->projectDir.'/vendor/acme/cache', $link);

        $this->copy(['include' => ['bin', 'vendor'], 'exclude' => ['config/secrets', 'var']]);

        $this->assertFileExists($this->payload.'/vendor/acme/conf/services.yaml');
        $this->assertDirectoryDoesNotExist($this->payload.'/vendor/acme/conf/secrets', 'Excluded where it is');
        $this->assertDirectoryDoesNotExist($this->payload.'/vendor/acme/cache', 'In an excluded folder');
    }

    #[DataProvider('provideLinks')]
    public function testALinkToADecryptionKeyStopsTheCopy(string $link)
    {
        (new Filesystem())->dumpFile($this->projectDir.'/config/secrets/prod/prod.decrypt.private.php', '<?php return "key";');
        $this->link($this->projectDir.'/config/secrets', $this->projectDir.'/vendor/acme/secrets', $link);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('it must never be shipped');

        $this->copy(['include' => ['bin', 'vendor'], 'exclude' => [], 'exclude_defaults' => false]);
    }

    public function testTheOutputIsNotShipped()
    {
        (new Filesystem())->dumpFile($this->projectDir.'/build/app/old.zip', 'zip');

        $this->copy(['include' => ['.']]);

        $this->assertFileExists($this->payload.'/bin/app.php');
        $this->assertFileExists($this->payload.'/packages/lib/src/functions.php');
        $this->assertDirectoryDoesNotExist($this->payload.'/build/app');
    }

    public static function provideFilesThatNeverShip(): iterable
    {
        foreach (['.env.local', '.env.prod.local', 'config/.env.local', 'auth.json'] as $file) {
            yield $file.', listed' => [$file, ['include' => ['bin', $file]]];
            yield $file.', listed, without the default excludes' => [$file, ['include' => ['bin', $file], 'exclude_defaults' => false]];
            yield $file.', without the default excludes' => [$file, ['include' => ['.'], 'exclude_defaults' => false]];
        }
    }

    #[DataProvider('provideFilesThatNeverShip')]
    public function testFilesThatNeverShip(string $secret, array $build)
    {
        (new Filesystem())->dumpFile($this->projectDir.'/'.$secret, 'secret');

        $this->copy($build);

        $this->assertFileExists($this->payload.'/bin/app.php');
        $this->assertFileDoesNotExist($this->payload.'/'.$secret);
        $this->assertSame([str_ends_with($secret, 'auth.json')
            ? \sprintf('warning: "%s" holds the credentials of Composer and never ships', $secret)
            : \sprintf('warning: "%s" may hold secrets and never ships: set APP_ENV with "runtime.env.set", or list the .env.local.php of "composer dump-env prod" in "build.include"', $secret),
        ], $this->log);
    }

    public static function provideDumps(): iterable
    {
        yield '.env.local.php' => ['.env.local.php'];
        yield '.env.prod.local.php' => ['config/.env.prod.local.php'];
    }

    #[DataProvider('provideDumps')]
    public function testDumpsAreSkippedUnlessIncluded(string $dump)
    {
        (new Filesystem())->dumpFile($this->projectDir.'/'.$dump, '<?php return [];');

        $this->copy(['include' => ['.'], 'exclude_defaults' => false]);

        $this->assertFileDoesNotExist($this->payload.'/'.$dump);
        $this->assertSame([\sprintf('warning: "%s" may hold secrets and is not shipped: list it in "build.include" to ship it, or set APP_ENV with "runtime.env.set"', $dump)], $this->log);
    }

    #[DataProvider('provideDumps')]
    public function testAnIncludedDumpIsShipped(string $dump)
    {
        (new Filesystem())->dumpFile($this->projectDir.'/'.$dump, '<?php return [];');

        $this->copy(['include' => ['bin', $dump]]);

        $this->assertFileExists($this->payload.'/'.$dump);
        $this->assertSame([\sprintf('warning: "%s" may hold secrets and is shipped, as "build.include" lists it', $dump)], $this->log);
    }

    public function testTheEnvDumpShipsWithTheDefaultExcludesWhenIncluded()
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->projectDir.'/.env.local.php', '<?php return ["APP_ENV" => "prod"];');
        $fs->dumpFile($this->projectDir.'/config/.env.local.php', '<?php return [];');

        $this->copy(['include' => ['bin', 'config', '.env.local.php']]);

        $this->assertFileExists($this->payload.'/.env.local.php');
        $this->assertFileDoesNotExist($this->payload.'/config/.env.local.php');
        $this->assertSame([
            'warning: "config/.env.local.php" may hold secrets and is not shipped: list it in "build.include" to ship it, or set APP_ENV with "runtime.env.set"',
            'warning: ".env.local.php" may hold secrets and is shipped, as "build.include" lists it',
        ], $this->log);
    }

    public static function provideExcludedSecrets(): iterable
    {
        yield '.env.local' => ['.env.local'];
        yield '.env.prod.local' => ['.env.prod.local'];
        yield 'auth.json' => ['auth.json'];
    }

    #[DataProvider('provideExcludedSecrets')]
    public function testSecretsAreNotShippedWithTheDefaults(string $secret)
    {
        (new Filesystem())->dumpFile($this->projectDir.'/'.$secret, 'secret');

        $this->copy(['include' => ['.'], 'exclude' => ['*.log']]);

        $this->assertFileExists($this->payload.'/bin/app.php');
        $this->assertFileDoesNotExist($this->payload.'/'.$secret);
        $this->assertSame([], $this->log);
    }

    public function testAnIncludedDecryptionKeyStopsTheCopy()
    {
        (new Filesystem())->dumpFile($this->projectDir.'/config/secrets/prod/prod.decrypt.private.php', '<?php return "key";');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('it must never be shipped');

        $this->copy(['include' => ['bin', 'config/secrets/prod/prod.decrypt.private.php']]);
    }

    public function testALinkToADumpShipsWhenListed()
    {
        (new Filesystem())->dumpFile($this->projectDir.'/.env.local.php', '<?php return [];');
        $this->link($this->projectDir.'/.env.local.php', $this->projectDir.'/config/env.php', 'symlink');

        $this->copy(['include' => ['bin', 'config/env.php']]);

        $this->assertFileExists($this->payload.'/config/env.php');
        $this->assertSame(['warning: "config/env.php" links to ".env.local.php", which may hold secrets, and is shipped, as "build.include" lists it'], $this->log);
    }

    public function testALinkToADumpIsNotShippedUnlessListed()
    {
        (new Filesystem())->dumpFile($this->projectDir.'/.env.local.php', '<?php return [];');
        $this->link($this->projectDir.'/.env.local.php', $this->projectDir.'/config/env.php', 'symlink');

        $this->copy(['include' => ['bin', 'config']]);

        $this->assertFileDoesNotExist($this->payload.'/config/env.php');
        $this->assertSame(['warning: "config/env.php" links to ".env.local.php", which may hold secrets and is never shipped'], $this->log);
    }

    public function testAListedLinkToAFileThatNeverShipsIsNotShipped()
    {
        (new Filesystem())->dumpFile($this->projectDir.'/.env.local', 'APP_SECRET=s');
        $this->link($this->projectDir.'/.env.local', $this->projectDir.'/config/env.txt', 'symlink');

        $this->copy(['include' => ['bin', 'config/env.txt'], 'exclude_defaults' => false]);

        $this->assertFileDoesNotExist($this->payload.'/config/env.txt');
        $this->assertSame(['warning: "config/env.txt" links to ".env.local", which may hold secrets and is never shipped'], $this->log);
    }

    public function testAFileListedTwiceIsCopiedOnce()
    {
        (new Filesystem())->dumpFile($this->projectDir.'/config/.env.local.php', '<?php return [];');

        $this->copy(['include' => ['bin', 'config', 'config/.env.local.php']]);

        $this->assertFileExists($this->payload.'/config/.env.local.php');
        $this->assertSame(['warning: "config/.env.local.php" may hold secrets and is shipped, as "build.include" lists it'], $this->log);
    }

    public function testALinkToASecretIsNotShipped()
    {
        (new Filesystem())->dumpFile($this->projectDir.'/auth.json', '{}');
        $this->link($this->projectDir.'/auth.json', $this->projectDir.'/vendor/credentials.txt', 'symlink');

        $this->copy(['include' => ['bin', 'vendor'], 'exclude_defaults' => false]);

        $this->assertFileExists($this->payload.'/vendor/autoload.php');
        $this->assertFileDoesNotExist($this->payload.'/vendor/credentials.txt');
        $this->assertSame(['warning: "vendor/credentials.txt" links to "auth.json", which may hold secrets and is never shipped'], $this->log);
    }

    private function copy(array $build): void
    {
        $config = (new ConfigLoader())->process(['app' => ['name' => 'Notes'], 'build' => $build], $this->projectDir);
        (new PayloadCopier(new LogRecorder(function (string $message): void { $this->log[] = $message; })))->copy($config, $this->payload);
    }

    private function link(string $target, string $link, string $kind): void
    {
        (new Filesystem())->mkdir(\dirname($link));
        if ('symlink' === $kind) {
            if (!@symlink($target, $link)) {
                $this->markTestSkipped('Symbolic links cannot be made here.');
            }

            return;
        }
        if ('\\' !== \DIRECTORY_SEPARATOR) {
            $this->markTestSkipped('Junctions are Windows folders.');
        }
        exec(\sprintf('mklink /J "%s" "%s" 2>&1', strtr($link, '/', '\\'), strtr($target, '/', '\\')), $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
        $this->junctions[] = $link;
    }
}
