<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Build\Packager;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\TuiApp\Build\Packager\LinuxPackager;
use Symfony\Component\TuiApp\Build\Packager\MacosPackager;
use Symfony\Component\TuiApp\Build\Packager\WindowsPackager;
use Symfony\Component\TuiApp\Exception\RuntimeException;
use Symfony\Component\TuiApp\Tests\Build\ProcessRunnerStub;
use Symfony\Component\TuiApp\Tests\Build\Runtime\RuntimeTestCase;

class PackagersTest extends RuntimeTestCase
{
    public function testMacosSignsNotarizesAndMakesADiskImage()
    {
        $target = $this->target('macos-arm64');
        $stub = new ProcessRunnerStub(static function (array $command) use ($target): array {
            if (str_ends_with($command[0], 'build-app.sh')) {
                (new Filesystem())->mkdir($target->outputDir.'/Notes.app');

                return [0, "signing\n".$target->outputDir."/Notes.app\n"];
            }

            return [0];
        });
        $config = $this->config([
            'app' => ['version' => '1.2.0', 'icons' => ['macos' => 'icon.icns']],
            'signing' => ['macos' => ['identity' => 'Developer ID Application: Acme', 'notarize' => true, 'keychain_profile' => 'acme']],
            'build' => ['formats' => ['macos' => ['app', 'zip', 'dmg']]],
        ]);

        $packages = (new MacosPackager($this->runner($stub)))->package($config, $target);

        $app = $target->outputDir.'/Notes.app';
        $this->assertSame([$this->dir.'/hosts/macos/build-app.sh', '--icon', $this->projectDir.'/icon.icns', '--sign', 'Developer ID Application: Acme', '--notarize', 'acme', $target->manifest, $target->payloadDir, $target->outputDir], $stub->commands()[0]);
        $this->assertSame(['ditto', '-c', '-k', '--keepParent', $app, $target->outputDir.'/Notes-1.2.0.zip'], $stub->commands()[1]);
        $this->assertSame('hdiutil', $stub->commands()[2][0]);
        $this->assertSame([$app, $target->outputDir.'/Notes-1.2.0.zip', $target->outputDir.'/Notes-1.2.0.dmg'], $packages);
    }

    public function testMacosWithoutABundle()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The macOS packaging did not write');

        (new MacosPackager($this->runner(new ProcessRunnerStub())))->package($this->config([]), $this->target('macos-arm64'));
    }

    public function testLinuxRunsInItsRunner()
    {
        $target = $this->target('linux-arm64', ['orb', '-m', 'arm']);
        $stub = new ProcessRunnerStub(static function () use ($target): array {
            (new Filesystem())->dumpFile($tar = $target->outputDir.'/notes.tar.gz', '');

            return [0, "packaging\n".$tar."\n/not/written.deb\n"];
        });

        $packages = (new LinuxPackager($this->runner($stub)))->package($this->config(['app' => ['icon' => 'icon.svg'], 'build' => ['formats' => ['linux' => ['tar', 'deb']]]]), $target);

        $this->assertSame([$target->outputDir.'/notes.tar.gz'], $packages);
        $this->assertSame(['orb', '-m', 'arm', $this->dir.'/hosts/linux/package.sh', '--icon', $this->projectDir.'/icon.svg', '--arch', 'arm64', '--formats', 'tar,deb', $target->manifest, $target->payloadDir, $target->outputDir], $stub->commands()[0]);
    }

    public function testLinuxThatWritesNothing()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The packaging wrote nothing');

        (new LinuxPackager($this->runner(new ProcessRunnerStub())))->package($this->config([]), $this->target('linux-x86_64'));
    }

    public function testWindowsSignsWithACertificate()
    {
        $target = $this->target('windows-arm64');
        $stub = new ProcessRunnerStub(static function () use ($target): array {
            (new Filesystem())->dumpFile($exe = $target->outputDir.'/Notes Setup.exe', '');

            return [0, $exe];
        });

        $packages = (new WindowsPackager($this->runner($stub)))->package($this->config(['signing' => ['windows' => ['certificate' => 'acme.pfx']]]), $target);

        $this->assertSame([$target->outputDir.'/Notes Setup.exe'], $packages);
        $this->assertSame(['powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $this->dir.'/hosts/windows/package.ps1', '-Manifest', $target->manifest, '-Payload', $target->payloadDir, '-Out', $target->outputDir, '-Arch', 'arm64', '-Formats', 'exe', '-Certificate', $this->projectDir.'/acme.pfx', '-PasswordEnv', 'TUI_APP_SIGNING_PASSWORD', '-TimestampUrl', 'http://timestamp.digicert.com'], $stub->commands()[0]);
    }
}
