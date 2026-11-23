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
 * An app bundle, signed when an identity is configured, in a zip or a disk image.
 *
 * @internal
 */
final class MacosPackager implements PackagerInterface
{
    public function __construct(
        private readonly CommandRunner $runner,
    ) {
    }

    public function package(AppConfig $config, BuildTarget $target): array
    {
        $command = [$target->hostsDir.'/macos/build-app.sh'];
        if (null !== $icon = $config->getIcon('macos')) {
            array_push($command, '--icon', $icon);
        }
        $signing = $config->getMacosSigning();
        if (null !== $signing['identity']) {
            array_push($command, '--sign', $signing['identity']);
        }
        if ($signing['notarize']) {
            array_push($command, '--notarize', (string) $signing['keychain_profile']);
        }
        array_push($command, $target->manifest, $target->payloadDir, $target->outputDir);

        // The script prints the path of the bundle last
        $printed = explode("\n", trim($this->runner->run($command, timeout: null)));
        $app = end($printed) ?: $target->outputDir.'/'.$config->getName().'.app';
        if (!is_dir($app)) {
            throw new RuntimeException(\sprintf('The macOS packaging did not write "%s".', $app));
        }

        $packages = [];
        $formats = $config->getFormats('macos');
        $base = $target->outputDir.'/'.$config->getName().'-'.$config->getVersion();
        if (\in_array('app', $formats, true)) {
            $packages[] = $app;
        }
        if (\in_array('zip', $formats, true)) {
            $this->runner->run(['ditto', '-c', '-k', '--keepParent', $app, $zip = $base.'.zip']);
            $packages[] = $zip;
        }
        if (\in_array('dmg', $formats, true)) {
            $this->runner->run(['hdiutil', 'create', '-volname', $config->getName(), '-srcfolder', $app, '-ov', '-format', 'UDZO', $dmg = $base.'.dmg'], timeout: null);
            $packages[] = $dmg;
        }

        return $packages;
    }
}
