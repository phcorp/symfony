<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Build\Packager;

use Symfony\Component\TuiApp\Build\BuildTarget;
use Symfony\Component\TuiApp\Build\CommandRunner;
use Symfony\Component\TuiApp\Config\AppConfig;

/**
 * An installer or a zip, signed when a certificate is configured.
 *
 * @internal
 */
final class WindowsPackager implements PackagerInterface
{
    use PrintedPackagesTrait;

    public function __construct(
        private readonly CommandRunner $runner,
    ) {
    }

    public function package(AppConfig $config, BuildTarget $target): array
    {
        $command = ['powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $target->hostsDir.'/windows/package.ps1', '-Manifest', $target->manifest, '-Payload', $target->payloadDir, '-Out', $target->outputDir, '-Arch', $target->arch, '-Formats', implode(',', $config->getFormats('windows'))];
        if (null !== $icon = $config->getIcon('windows')) {
            array_push($command, '-Icon', $icon);
        }
        $signing = $config->getWindowsSigning();
        if (null !== $signing['certificate']) {
            array_push($command, '-Certificate', $config->path($signing['certificate']), '-PasswordEnv', $signing['password_env'], '-TimestampUrl', $signing['timestamp_url']);
        }

        return self::printedPackages($this->runner->run($command, timeout: null), $target->outputDir);
    }
}
