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
 * tar, deb and rpm packages, built by the package script of the hosts, in a runner off Linux.
 *
 * @internal
 */
final class LinuxPackager implements PackagerInterface
{
    use PrintedPackagesTrait;

    public function __construct(
        private readonly CommandRunner $runner,
    ) {
    }

    public function package(AppConfig $config, BuildTarget $target): array
    {
        $command = [...$target->prefix, $target->hostsDir.'/linux/package.sh'];
        if (null !== $icon = $config->getIcon('linux')) {
            array_push($command, '--icon', $icon);
        }
        array_push($command, '--arch', $target->arch, '--formats', implode(',', $config->getFormats('linux')), $target->manifest, $target->payloadDir, $target->outputDir);

        return self::printedPackages($this->runner->run($command, timeout: null), $target->outputDir);
    }
}
