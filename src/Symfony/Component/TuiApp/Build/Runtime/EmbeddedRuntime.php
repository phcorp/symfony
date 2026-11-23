<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Build\Runtime;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\TuiApp\Build\BuildTarget;
use Symfony\Component\TuiApp\Build\CommandRunner;
use Symfony\Component\TuiApp\Build\ComposerExtensions;
use Symfony\Component\TuiApp\Config\AppConfig;

/**
 * A static PHP built for the application by the build-php script of the hosts, with
 * the configured extensions and those the project requires.
 *
 * @internal
 */
final class EmbeddedRuntime implements RuntimeInterface
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly LoggerInterface $logger,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function check(AppConfig $config): array
    {
        return [];
    }

    public function prepare(AppConfig $config, BuildTarget $target): void
    {
        if (\in_array($target->os, ['ios', 'android'], true)) {
            (new MobileRuntime($this->runner, $this->logger))->prepare($config, $target);

            return;
        }
        $version = $config->getPhpVersion();
        $extensions = $config->getPhpExtensions();
        $required = (new ComposerExtensions($this->logger))->required($config->getProjectDir(), $extensions, $version, $target->name, $target->hostsDir.'/runtime/static-extensions.list');
        if ($required) {
            $this->logger->info('[{target}] adding {extensions}', ['target' => $target->name, 'extensions' => ComposerExtensions::describe($required)]);
            $extensions = [...$extensions, ...array_keys($required)];
        }

        $this->logger->info('[{target}] building PHP {version} with {extensions}', ['target' => $target->name, 'version' => $version, 'extensions' => implode(', ', $extensions)]);
        $windows = 'windows' === $target->os;
        $php = $target->payloadDir.'/runtime/'.($windows ? 'php.exe' : 'php');
        $this->filesystem->mkdir(\dirname($php));
        $command = $windows
            ? ['powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $target->hostsDir.'/runtime/build-php.ps1', '-Version', $version, '-Extensions', implode(',', $extensions), '-Out', $php]
            : [...$target->prefix, $target->hostsDir.'/runtime/build-php.sh', '--version', $version, '--extensions', implode(',', $extensions), '--out', $php];

        // What the script does, as it goes: builds PHP, which takes minutes, or takes it from its cache
        $this->runner->run($command, timeout: null, onOutput: function (string $line) use ($target): void {
            if (str_starts_with(trim($line), 'build-php: ')) {
                $this->logger->info('[{target}] {step}', ['target' => $target->name, 'step' => substr(trim($line), 11)]);
            }
        });
    }
}
