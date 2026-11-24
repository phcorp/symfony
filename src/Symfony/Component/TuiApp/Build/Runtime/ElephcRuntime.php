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
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\TuiApp\Build\BuildTarget;
use Symfony\Component\TuiApp\Build\CommandRunner;
use Symfony\Component\TuiApp\Build\ElephcException;
use Symfony\Component\TuiApp\Build\VersionConstraint;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Exception\RuntimeException;

/**
 * The application compiled to a native executable with elephc (beta).
 *
 * @internal
 */
final class ElephcRuntime implements RuntimeInterface
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly LoggerInterface $logger,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function check(AppConfig $config): array
    {
        return ['The elephc runtime is a beta: it compiles a subset of PHP, and the build stops on what it does not support.'];
    }

    public function prepare(AppConfig $config, BuildTarget $target): void
    {
        $elephc = $config->getElephc();
        $binary = str_contains($elephc['binary'], '/') ? $config->path($elephc['binary']) : (new ExecutableFinder())->find($elephc['binary']);
        if (null === $binary || !is_file($binary)) {
            throw new RuntimeException(\sprintf('elephc is not installed: "%s" was not found. See https://elephc.dev.', $elephc['binary']));
        }
        if (!$target->isCurrent()) {
            throw new RuntimeException(\sprintf('elephc compiles the %s target on a %s computer; this one is %s.', $target->name, $target->name, AppConfig::currentTarget()));
        }

        $version = preg_match('/(\d+\.\d+\.\d+)/', $this->runner->run([$binary, '--version']), $match) ? $match[1] : '0.0.0';
        if (!VersionConstraint::satisfies($version, $elephc['version'])) {
            throw new RuntimeException(\sprintf('elephc %s does not match "runtime.elephc.version" (%s).', $version, $elephc['version']));
        }

        $this->logger->info('[{target}] compiling with elephc {version} (beta)', ['target' => $target->name, 'version' => $version]);
        $app = $target->payloadDir.'/app';
        foreach ($elephc['native'] as $library) {
            $this->runner->run([$binary, 'native', 'add', $library], $app);
        }

        $entry = $app.'/'.$config->getEntry();
        $command = [$binary, ...$elephc['flags']];
        if (null !== $elephc['heap_size']) {
            array_push($command, '--heap-size', (string) $elephc['heap_size']);
        }
        $command[] = $entry;

        [$status, $output, $errorOutput] = $this->runner->call($command, $app, null);
        if (0 !== $status) {
            throw ElephcException::fromOutput($output."\n".$errorOutput, $app);
        }

        // elephc writes the executable next to the entry script, named after it without ".php"
        $executable = substr($entry, 0, -4);
        if (!str_ends_with($entry, '.php') || !is_file($executable)) {
            throw new RuntimeException(\sprintf('elephc did not write "%s".', $executable));
        }
        $this->filesystem->rename($executable, $app.'/'.$config->getExecutableName(), true);
    }
}
