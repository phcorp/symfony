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
use Symfony\Component\TuiApp\Exception\RuntimeException;

/**
 * A development iOS app bundle with embedded PHP.
 *
 * @internal
 */
final class IosPackager implements PackagerInterface
{
    public function __construct(
        private readonly CommandRunner $runner,
    ) {
    }

    public function package(AppConfig $config, BuildTarget $target): array
    {
        $command = [$target->hostsDir.'/ios/build-app.sh'];
        array_push($command, '--target', $target->name);
        $signing = $config->toArray()['signing']['ios'];
        if (null !== $signing['identity']) {
            array_push($command, '--sign', $signing['identity']);
        }
        if (null !== $signing['provisioning_profile']) {
            array_push($command, '--profile', $config->path($signing['provisioning_profile']));
        }
        if (null !== $icon = $config->getIcon('ios')) {
            array_push($command, '--icon', $icon);
        }
        array_push($command, $target->manifest, $target->payloadDir, $target->outputDir);
        $this->runner->run($command, null, null);
        $package = $target->outputDir.'/Application.app';
        if (!is_dir($package)) {
            throw new RuntimeException(\sprintf('The iOS packager did not write "%s".', $package));
        }

        return [$package];
    }
}
