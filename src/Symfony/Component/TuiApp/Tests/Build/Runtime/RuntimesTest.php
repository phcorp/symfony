<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Build\Runtime;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\TuiApp\Build\ElephcException;
use Symfony\Component\TuiApp\Build\Runtime\CustomRuntime;
use Symfony\Component\TuiApp\Build\Runtime\ElephcRuntime;
use Symfony\Component\TuiApp\Build\Runtime\EmbeddedRuntime;
use Symfony\Component\TuiApp\Build\Runtime\SystemRuntime;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Config\Configuration;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;
use Symfony\Component\TuiApp\Exception\RuntimeException;
use Symfony\Component\TuiApp\Tests\Build\ProcessRunnerStub;

class RuntimesTest extends RuntimeTestCase
{
    public function testEmbeddedBuildsPhpWithTheExtensionsThePackagesRequire()
    {
        $stub = new ProcessRunnerStub(static fn (): array => [0, "build-php: PHP 8.5.13 from the cache\nnot a step\n"]);
        $target = $this->target('linux-arm64', ['orb', '-m', 'arm']);

        (new EmbeddedRuntime($this->runner($stub), $this->logger()))->prepare($this->config([]), $target);

        $this->assertSame([['orb', '-m', 'arm', $this->dir.'/hosts/runtime/build-php.sh', '--version', '8.5', '--extensions', implode(',', [...Configuration::DEFAULT_EXTENSIONS, 'dom']), '--out', $target->payloadDir.'/runtime/php']], $stub->commands());
        $this->assertSame([
            '[linux-arm64] adding dom, required by symfony/tui-html',
            '[linux-arm64] building PHP 8.5 with '.implode(', ', [...Configuration::DEFAULT_EXTENSIONS, 'dom']),
            '[linux-arm64] PHP 8.5.13 from the cache',
        ], $this->log);
        $this->assertDirectoryExists($target->payloadDir.'/runtime');
    }

    public function testEmbeddedOnWindowsRunsThePowerShellScript()
    {
        $stub = new ProcessRunnerStub();
        $target = $this->target('windows-x64');

        (new EmbeddedRuntime($this->runner($stub), $this->logger()))->prepare($this->config(['runtime' => ['php' => ['version' => '8.4', 'extensions' => ['ctype']]]]), $target);

        $this->assertSame([['powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $this->dir.'/hosts/runtime/build-php.ps1', '-Version', '8.4', '-Extensions', 'ctype,dom', '-Out', $target->payloadDir.'/runtime/php.exe']], $stub->commands());
    }

    public function testSystemSaysWhatThePhpOfTheComputerMustHave()
    {
        (new SystemRuntime($this->logger()))->prepare($this->config(['runtime' => ['mode' => 'system']]), $this->target('linux-x86_64'));

        $this->assertSame(['[linux-x86_64] the PHP of the computer must have dom, required by symfony/tui-html'], $this->log);
    }

    public function testCustomShipsAndChecksTheBinary()
    {
        file_put_contents($php = $this->dir.'/php', 'binary');
        $stub = new ProcessRunnerStub(static fn (): array => [0, "8.5.12\ncore,ctype,dom"]);
        $target = $this->target('linux-arm64', ['orb']);
        $config = $this->config(['runtime' => ['mode' => 'custom', 'php' => ['path' => $php, 'extensions' => ['ctype']]]]);

        $this->assertSame([], (new CustomRuntime($this->runner($stub), $this->logger()))->check($config));
        (new CustomRuntime($this->runner($stub), $this->logger()))->prepare($config, $target);

        $this->assertSame('binary', file_get_contents($target->payloadDir.'/runtime/php'));
        $this->assertSame(['orb', $target->payloadDir.'/runtime/php', '-n', '-r'], \array_slice($stub->commands()[0], 0, 4));
    }

    public function testCustomBinaryMissingAnExtension()
    {
        file_put_contents($php = $this->dir.'/php', 'binary');
        $stub = new ProcessRunnerStub(static fn (): array => [0, "8.5.12\ncore,ctype"]);
        $config = $this->config(['runtime' => ['mode' => 'custom', 'php' => ['path' => $php, 'extensions' => ['ctype']]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The PHP binary lacks the extension dom, required by symfony/tui-html.');

        (new CustomRuntime($this->runner($stub), $this->logger()))->prepare($config, $this->target(AppConfig::currentTarget()));
    }

    public function testCustomBinaryOfAnotherSystemIsNotChecked()
    {
        file_put_contents($php = $this->dir.'/php', 'binary');
        $stub = new ProcessRunnerStub();
        $other = 'windows' === explode('-', AppConfig::currentTarget())[0] ? 'macos-arm64' : 'windows-x64';

        (new CustomRuntime($this->runner($stub), $this->logger()))->prepare($this->config(['runtime' => ['mode' => 'custom', 'php' => ['path' => $php]]]), $target = $this->target($other));

        $this->assertSame([], $stub->commands());
        $this->assertContains(\sprintf('warning: the PHP binary of the %s target cannot be checked on this computer', $other), $this->log);
        $this->assertFileExists($target->payloadDir.'/runtime/'.('windows-x64' === $other ? 'php.exe' : 'php'));
    }

    public function testCustomBinaryMustExist()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('of the custom runtime does not exist');

        (new CustomRuntime($this->runner(new ProcessRunnerStub()), $this->logger()))->check($this->config(['runtime' => ['mode' => 'custom', 'php' => ['path' => 'bin/php']]]));
    }

    public function testElephcCompilesAndNamesTheExecutable()
    {
        $elephc = $this->elephc();
        $target = $this->target(AppConfig::currentTarget());
        (new Filesystem())->dumpFile($target->payloadDir.'/app/bin/app.php', '<?php');
        $stub = new ProcessRunnerStub(static function (array $command) use ($target): array {
            if ('--version' === ($command[1] ?? null)) {
                return [0, "elephc 0.27.8\n"];
            }
            if (str_ends_with(end($command), 'bin/app.php')) {
                touch($target->payloadDir.'/app/bin/app');
            }

            return [0];
        });
        $config = $this->config(['app' => ['name' => 'Acme Notes'], 'runtime' => ['mode' => 'elephc', 'elephc' => ['binary' => $elephc, 'heap_size' => 1024, 'flags' => ['--release']]], 'build' => ['targets' => ['linux-x86_64']]]);

        $runtime = new ElephcRuntime($this->runner($stub), $this->logger());
        $this->assertStringContainsString('beta', $runtime->check($config)[0]);
        $runtime->prepare($config, $target);

        $app = $target->payloadDir.'/app';
        $this->assertSame([[$elephc, '--version'], [$elephc, 'native', 'add', 'pcre2'], [$elephc, '--release', '--heap-size', '1024', $app.'/bin/app.php']], $stub->commands());
        $this->assertSame($app, $stub->commands[2]['cwd']);
        $this->assertFileExists($app.'/acme-notes');
        $this->assertFileDoesNotExist($app.'/bin/app');
    }

    public function testElephcThatWritesNothing()
    {
        $target = $this->target(AppConfig::currentTarget());
        $stub = new ProcessRunnerStub(static fn (array $command): array => [0, '--version' === ($command[1] ?? null) ? 'elephc 0.27.8' : '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('elephc did not write');

        (new ElephcRuntime($this->runner($stub), $this->logger()))->prepare($this->config(['runtime' => ['mode' => 'elephc', 'elephc' => ['binary' => $this->elephc()]], 'build' => ['targets' => ['linux-x86_64']]]), $target);
    }

    public function testElephcProblems()
    {
        $stub = new ProcessRunnerStub(static fn (array $command): array => '--version' === ($command[1] ?? null) ? [0, 'elephc 0.27.8'] : ('native' === ($command[1] ?? null) ? [0] : [1, '', file_get_contents(__DIR__.'/../../Fixtures/elephc-output.txt')]));

        try {
            (new ElephcRuntime($this->runner($stub), $this->logger()))->prepare($this->config(['runtime' => ['mode' => 'elephc', 'elephc' => ['binary' => $this->elephc()]], 'build' => ['targets' => ['linux-x86_64']]]), $this->target(AppConfig::currentTarget()));
            $this->fail('elephc failed');
        } catch (ElephcException $e) {
            $this->assertCount(40, $e->getProblems());
        }
    }

    public function testElephcVersionMustMatch()
    {
        $stub = new ProcessRunnerStub(static fn (): array => [0, 'elephc 0.26.1']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('elephc 0.26.1 does not match "runtime.elephc.version" (>=0.27 <0.29)');

        (new ElephcRuntime($this->runner($stub), $this->logger()))->prepare($this->config(['runtime' => ['mode' => 'elephc', 'elephc' => ['binary' => $this->elephc(), 'version' => '>=0.27 <0.29']], 'build' => ['targets' => ['linux-x86_64']]]), $this->target(AppConfig::currentTarget()));
    }

    private function elephc(): string
    {
        file_put_contents($elephc = $this->dir.'/elephc', '');

        return $elephc;
    }
}
