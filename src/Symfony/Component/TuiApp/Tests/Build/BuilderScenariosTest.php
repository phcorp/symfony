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
use Symfony\Component\TuiApp\Build\Builder;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Config\ConfigLoader;
use Symfony\Component\TuiApp\Exception\RuntimeException;

/**
 * Builds of a Linux target, on any system: the commands are recorded, not run.
 */
class BuilderScenariosTest extends TestCase
{
    private string $dir;
    private string $projectDir;
    private string $hosts;
    private array $log = [];
    private ProcessRunnerStub $processes;

    protected function setUp(): void
    {
        $this->dir = Path::canonicalize(sys_get_temp_dir()).'/tui-app-scenarios-'.bin2hex(random_bytes(4));
        $this->projectDir = $this->dir.'/project';
        $this->hosts = $this->projectDir.'/hosts';
        $fs = new Filesystem();
        $fs->dumpFile($this->projectDir.'/bin/app.php', '<?php');
        $fs->dumpFile($this->projectDir.'/composer.json', '{"name": "acme/notes"}');
        $fs->dumpFile($this->hosts.'/contract/protocol.json', '{}');
        $this->processes = new ProcessRunnerStub(static function (array $command): array {
            if (!\in_array('--arch', $command, true)) {
                return [0, "8.5.12\ncore,ctype"];
            }
            touch($tar = end($command).'/notes.tar.gz');

            return [0, $tar];
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem())->chmod($this->dir, 0o755, 0o000, true);
        (new Filesystem())->remove($this->dir);
    }

    public function testBundledHostsDoNotDependOnTheWorkingDirectory()
    {
        $cwd = getcwd();
        chdir(sys_get_temp_dir());
        try {
            $packages = $this->builder()->build($this->config([]));
        } finally {
            chdir($cwd);
        }

        $this->assertSame([$this->projectDir.'/build/app/linux-x86_64/notes.tar.gz'], $packages);
        $this->assertSame(\dirname(__DIR__, 2).'/Resources/linux/package.sh', $this->processes->commands()[0][1]);
    }

    public function testTheManifestIsWrittenAndTheWorkDirectoryRemoved()
    {
        $manifest = null;
        $this->processes = new ProcessRunnerStub(static function (array $command) use (&$manifest): array {
            $manifest = json_decode(file_get_contents($command[\count($command) - 3]), true);
            touch($tar = end($command).'/notes.tar.gz');

            return [0, $tar];
        });

        $this->builder()->build($this->config([]));

        $this->assertSame('Notes', $manifest['app']['name']);
        $this->assertDirectoryDoesNotExist($this->projectDir.'/build/app/.work');
    }

    public function testAWorkDirectoryThatCannotBeRemovedIsNotAnError()
    {
        if ('\\' === \DIRECTORY_SEPARATOR || (\function_exists('posix_getuid') && 0 === posix_getuid())) {
            $this->markTestSkipped('A folder cannot be made read-only here.');
        }
        $locked = $this->projectDir.'/build/app/.work/locked';
        $this->processes = new ProcessRunnerStub(static function (array $command) use ($locked): array {
            (new Filesystem())->dumpFile($locked.'/file', '');
            chmod($locked, 0o500);
            touch($tar = end($command).'/notes.tar.gz');

            return [0, $tar];
        });

        $packages = $this->builder()->build($this->config([]));

        $this->assertSame([$this->projectDir.'/build/app/linux-x86_64/notes.tar.gz'], $packages);
        $this->assertCount(1, $warnings = preg_grep('/^warning: .*\.work/', $this->log));
        $this->assertStringContainsString(\sprintf('"%s/build/app/.work" could not be removed; delete it by hand', $this->projectDir), reset($warnings));
    }

    public function testAWorkDirectoryLeftByAPreviousBuildThatCannotBeRemoved()
    {
        if ('\\' === \DIRECTORY_SEPARATOR || (\function_exists('posix_getuid') && 0 === posix_getuid())) {
            $this->markTestSkipped('A folder cannot be made read-only here.');
        }
        $work = $this->projectDir.'/build/app/.work/linux-x86_64';
        (new Filesystem())->dumpFile($work.'/locked/file', '');
        chmod($work.'/locked', 0o500);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(\sprintf('The work folder "%s" of a previous build cannot be removed; delete it by hand.', $work));

        $this->builder()->build($this->config([]));
    }

    public function testACopyThatFailsStopsTheBuild()
    {
        if ('\\' === \DIRECTORY_SEPARATOR || (\function_exists('posix_getuid') && 0 === posix_getuid())) {
            $this->markTestSkipped('A file cannot be made unreadable here.');
        }
        file_put_contents($php = $this->dir.'/php', "#!/bin/sh\n");
        chmod($php, 0o000);
        $config = $this->config(['runtime' => ['mode' => 'custom', 'php' => ['path' => $php, 'extensions' => []]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to copy');

        $this->builder()->build($config);
    }

    private function builder(): Builder
    {
        return new Builder(new LogRecorder(function (string $message): void { $this->log[] = $message; }), $this->processes(...));
    }

    private function processes(array $command, ?string $cwd, ?float $timeout, \Closure $callback): int
    {
        return ($this->processes)($command, $cwd, $timeout, $callback);
    }

    private function config(array $config): AppConfig
    {
        $config['app']['name'] = 'Notes';
        $config['runtime']['mode'] ??= 'system';
        $config['build']['targets'] = ['linux-x86_64'];
        $config['build']['formats']['linux'] = ['tar'];
        $config['build']['hosts']['linux_runners'] = ['x86_64' => ['runner']];

        return (new ConfigLoader())->process($config, $this->projectDir);
    }
}
