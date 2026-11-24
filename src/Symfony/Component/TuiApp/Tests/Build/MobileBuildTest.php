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
use Psr\Log\NullLogger;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\TuiApp\Build\Builder;
use Symfony\Component\TuiApp\Build\BuildTarget;
use Symfony\Component\TuiApp\Build\CommandRunner;
use Symfony\Component\TuiApp\Build\Packager\AndroidPackager;
use Symfony\Component\TuiApp\Build\Packager\IosPackager;
use Symfony\Component\TuiApp\Config\ConfigLoader;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;
use Symfony\Component\TuiApp\Exception\RuntimeException;

class MobileBuildTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/tui-mobile-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->directory.'/bin/app.php', '<?php');
        $filesystem->dumpFile($this->directory.'/composer.json', '{"name":"acme/notes"}');
        $filesystem->dumpFile($this->directory.'/hosts/contract/protocol.json', '{}');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public static function targets(): iterable
    {
        yield ['ios-arm64'];
        yield ['ios-simulator-arm64'];
        yield ['android-arm64'];
    }

    #[DataProvider('targets')]
    public function testAcceptsMobileTargets(string $target)
    {
        $config = (new ConfigLoader())->process(['build' => ['targets' => [$target]]], $this->directory);

        $this->assertSame([$target], $config->getTargets());
        $this->assertSame('embedded', $config->getRuntimeMode());
        $this->assertNull($config->toManifest($target)['launch']['command']);
    }

    public static function otherRuntimes(): iterable
    {
        foreach (['ios-arm64', 'ios-simulator-arm64', 'android-arm64'] as $target) {
            foreach (['system', 'custom', 'elephc'] as $mode) {
                yield [$target, $mode];
            }
        }
    }

    #[DataProvider('otherRuntimes')]
    public function testMobileRequiresEmbeddedPhp(string $target, string $mode)
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Mobile targets require the embedded PHP runtime.');

        (new ConfigLoader())->process(['runtime' => ['mode' => $mode, 'php' => ['path' => '/php']], 'build' => ['targets' => [$target]]], $this->directory);
    }

    #[RequiresOperatingSystemFamily('Darwin')]
    #[DataProvider('targets')]
    public function testBuildsTheMobileRuntimeAndPackagesThePayload(string $target)
    {
        $commands = [];
        $filesystem = new Filesystem();
        $runner = static function (array $command, ?string $cwd, ?float $timeout, \Closure $output) use (&$commands, $filesystem): int {
            $commands[] = $command;
            if (str_ends_with($command[0], '/mobile/build-runtime.sh')) {
                $filesystem->dumpFile($command[2].'/build/libs/libphp.a', 'static PHP');
            } else {
                $directory = end($command);
                $package = $directory.(str_ends_with($command[0], '/ios/build-app.sh') ? '/Application.app' : '/application.apk');
                if (str_ends_with($package, '.app')) {
                    $filesystem->mkdir($package);
                } else {
                    $filesystem->dumpFile($package, 'apk');
                }
                $output('out', $package."\n");
            }

            return 0;
        };
        $config = (new ConfigLoader())->process(['build' => ['targets' => [$target]]], $this->directory);

        $packages = (new Builder(null, $runner))->build($config);

        $this->assertCount(1, $packages);
        $this->assertFileExists($packages[0]);
        $this->assertCount(2, $commands);
        $this->assertSame(\dirname(__DIR__, 2).'/Resources/mobile/build-runtime.sh', $commands[0][0]);
        $this->assertSame($target, $commands[0][1]);
        $this->assertStringEndsWith('/payload/runtime', $commands[0][2]);
        $this->assertSame(\dirname(__DIR__, 2).'/Resources/'.(str_starts_with($target, 'ios-') ? 'ios' : 'android').'/build-app.sh', $commands[1][0]);
        if (str_starts_with($target, 'ios-')) {
            $this->assertSame(['--target', $target], \array_slice($commands[1], 1, 2));
        }
    }

    #[DataProvider('targets')]
    public function testMissingMobileArtifactIsReportedEvenWhenTheCommandSucceeds(string $target)
    {
        $config = (new ConfigLoader())->process([], $this->directory);
        $runner = new CommandRunner(new NullLogger(), static fn (): int => 0);
        $packager = str_starts_with($target, 'ios-') ? new IosPackager($runner) : new AndroidPackager($runner);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('packager did not write');

        $packager->package($config, new BuildTarget($target, [], $this->directory.'/hosts', $this->directory.'/work', $this->directory.'/output'));
    }

    #[DataProvider('targets')]
    public function testMobileSigningAndIconPathsRemainSingleArguments(string $target)
    {
        $config = (new ConfigLoader())->process(['app' => ['icon' => 'art/app icon.png'], 'signing' => ['ios' => ['identity' => 'Apple Development: A Name (TEAM)', 'provisioning_profile' => 'profiles/dev profile.mobileprovision']]], $this->directory);
        $build = new BuildTarget($target, [], $this->directory.'/hosts', $this->directory.'/work dir', $this->directory.'/output dir');
        $ios = str_starts_with($target, 'ios-');
        $artifact = $build->outputDir.($ios ? '/Application.app' : '/application.apk');
        $expected = [$build->hostsDir.($ios ? '/ios' : '/android').'/build-app.sh'];
        if ($ios) {
            array_push($expected, '--target', $target, '--sign', 'Apple Development: A Name (TEAM)', '--profile', $this->directory.'/profiles/dev profile.mobileprovision');
        }
        array_push($expected, '--icon', $this->directory.'/art/app icon.png', $build->manifest, $build->payloadDir, $build->outputDir);
        $runner = new CommandRunner(new NullLogger(), function (array $command) use ($expected, $artifact, $ios): int {
            $this->assertSame($expected, $command);
            $filesystem = new Filesystem();
            if ($ios) {
                $filesystem->mkdir($artifact);
            } else {
                $filesystem->dumpFile($artifact, 'apk');
            }

            return 0;
        });
        $packager = $ios ? new IosPackager($runner) : new AndroidPackager($runner);

        $this->assertSame([$artifact], $packager->package($config, $build));
    }

    public function testAndroidIdentifiersAreCheckedBeforeBuilding()
    {
        $config = (new ConfigLoader())->process(['app' => ['id' => 'com.acme.notes-app'], 'build' => ['targets' => ['android-arm64']]], $this->directory);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Android app.id');

        $config->check();
    }

    #[RequiresOperatingSystemFamily('Darwin')]
    public function testAnUnsupportedExtensionIsNotSilentlyOmitted()
    {
        $config = (new ConfigLoader())->process(['runtime' => ['php' => ['extensions' => ['pcntl']]], 'build' => ['targets' => ['ios-arm64']]], $this->directory);
        $called = false;
        $runner = static function () use (&$called): int {
            $called = true;

            return 0;
        };
        try {
            (new Builder(null, $runner))->build($config);
            $this->fail('An unsupported extension was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('pcntl', $exception->getMessage());
            $this->assertFalse($called);
        }
    }

    public function testIosSigningNeedsBothAnIdentityAndAProfile()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('iOS signing needs both an identity and a provisioning profile.');

        (new ConfigLoader())->process(['signing' => ['ios' => ['identity' => 'Development']]], $this->directory);
    }
}
