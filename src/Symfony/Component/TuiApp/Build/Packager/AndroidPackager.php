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
 * A debug-signed Android APK with embedded PHP.
 *
 * @internal
 */
final class AndroidPackager implements PackagerInterface
{
    public function __construct(
        private readonly CommandRunner $runner,
    ) {
    }

    public function package(AppConfig $config, BuildTarget $target): array
    {
        $command = [$target->hostsDir.'/android/build-app.sh'];
        if (null !== $icon = $config->getIcon('android')) {
            array_push($command, '--icon', $icon);
        }
        array_push($command, $target->manifest, $target->payloadDir, $target->outputDir);
        $this->runner->run($command, null, null);
        $package = $target->outputDir.'/application.apk';
        if (!is_file($package)) {
            throw new RuntimeException(\sprintf('The Android packager did not write "%s".', $package));
        }

        return [$package];
    }
}
