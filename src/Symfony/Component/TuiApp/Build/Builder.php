<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Build;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\TuiApp\Build\Packager\AndroidPackager;
use Symfony\Component\TuiApp\Build\Packager\IosPackager;
use Symfony\Component\TuiApp\Build\Packager\LinuxPackager;
use Symfony\Component\TuiApp\Build\Packager\MacosPackager;
use Symfony\Component\TuiApp\Build\Packager\PackagerInterface;
use Symfony\Component\TuiApp\Build\Packager\WindowsPackager;
use Symfony\Component\TuiApp\Build\Runtime\CustomRuntime;
use Symfony\Component\TuiApp\Build\Runtime\ElephcRuntime;
use Symfony\Component\TuiApp\Build\Runtime\EmbeddedRuntime;
use Symfony\Component\TuiApp\Build\Runtime\RuntimeInterface;
use Symfony\Component\TuiApp\Build\Runtime\SystemRuntime;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;
use Symfony\Component\TuiApp\Exception\LogicException;
use Symfony\Component\TuiApp\Exception\RuntimeException;

/**
 * Builds the packages of a native application for its targets:
 *
 *  1. copies what the application ships into a payload (app/);
 *  2. adds its PHP runtime, links it into a mobile host, or compiles it with elephc;
 *  3. writes the manifest the host reads;
 *  4. hands both to the packaging script of the target's host.
 *
 * The hosts and their scripts are bundled in the component resources.
 *
 * @experimental
 */
final class Builder
{
    private readonly LoggerInterface $logger;
    private readonly CommandRunner $runner;
    private readonly Filesystem $filesystem;

    /**
     * @param LoggerInterface|null $logger        Receives what the build is doing
     * @param \Closure|null        $processRunner Runs a command: fn (list<string> $command, ?string $cwd, ?float $timeout, \Closure(string $type, string $data): void $callback): int, the exit status
     */
    public function __construct(?LoggerInterface $logger = null, ?\Closure $processRunner = null)
    {
        $missing = array_keys(array_filter([
            'psr/log' => !interface_exists(LoggerInterface::class),
            'symfony/filesystem' => !class_exists(Filesystem::class),
            'symfony/process' => !class_exists(Process::class),
        ]));
        if ($missing) {
            throw new LogicException(\sprintf('Building the application requires %s. Try running "composer require %s".', implode(', ', $missing), implode(' ', $missing)));
        }

        $this->logger = $logger ?? new NullLogger();
        $this->runner = new CommandRunner($this->logger, $processRunner);
        $this->filesystem = new Filesystem();
    }

    /**
     * @param list<string>|null $targets The targets to build, among the configured ones; null builds them all
     *
     * @return list<string> The packages written
     */
    public function build(AppConfig $config, ?array $targets = null): array
    {
        $targets ??= $config->getTargets();
        if ($unknown = array_diff($targets, $config->getTargets())) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a configured target; add it to "build.targets" (configured: %s).', implode('", "', $unknown), implode(', ', $config->getTargets())));
        }

        $runtime = $this->runtime($config);
        foreach ([...$config->check(), ...$runtime->check($config)] as $warning) {
            $this->logger->warning($warning);
        }

        $work = $config->getOutputDir().'/.work';
        try {
            $packages = [];
            foreach ($targets as $target) {
                array_push($packages, ...$this->buildTarget($config, $target, $runtime, $work.'/'.$target));
            }
        } catch (IOExceptionInterface $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        try {
            $this->filesystem->remove($work);
        } catch (IOExceptionInterface $e) {
            $this->logger->warning('"{dir}" could not be removed; delete it by hand: {error}', ['dir' => $work, 'error' => $e->getMessage()]);
        }

        return $packages;
    }

    /**
     * Whether a version matches a constraint like ^0.27, ~1.2, >=1.0, ">=0.27 <0.29" or 1.2.3.
     *
     * @internal
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        return VersionConstraint::satisfies($version, $constraint);
    }

    /**
     * @return list<string>
     */
    private function buildTarget(AppConfig $config, string $name, RuntimeInterface $runtime, string $work): array
    {
        $os = explode('-', $name)[0];
        $current = AppConfig::currentTarget();
        $currentOs = explode('-', $current)[0];
        if ('ios' === $os && 'macos' !== $currentOs) {
            throw new RuntimeException('iOS targets build on macOS with Xcode only.');
        }
        if ('android' === $os && !\in_array($currentOs, ['macos', 'linux'], true)) {
            throw new RuntimeException('Android targets build on macOS or Linux with the Android SDK and NDK.');
        }
        if (!\in_array($os, ['ios', 'android', 'linux'], true) && $os !== $currentOs) {
            throw new RuntimeException(\sprintf('The %s target builds on %s only; this computer is %s. Build it there, or in CI.', $name, 'macos' === $os ? 'macOS' : 'Windows', $current));
        }
        $target = new BuildTarget($name, $this->prefix($config, $name), $config->getHostsDir(), $work, $config->getOutputDir().'/'.$name);

        try {
            $this->filesystem->remove($work);
        } catch (IOExceptionInterface $e) {
            throw new RuntimeException(\sprintf('The work folder "%s" of a previous build cannot be removed; delete it by hand. %s', $work, $e->getMessage()), 0, $e);
        }
        $this->filesystem->mkdir($target->payloadDir.'/app');

        $this->logger->info('[{target}] copying the application', ['target' => $name]);
        (new PayloadCopier($this->logger))->copy($config, $target->payloadDir.'/app');

        $runtime->prepare($config, $target);

        $this->filesystem->dumpFile($target->manifest, json_encode($config->toManifest($name), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
        $this->filesystem->mkdir($target->outputDir);

        $this->logger->info('[{target}] packaging', ['target' => $name]);

        return $this->packager($target)->package($config, $target);
    }

    private function runtime(AppConfig $config): RuntimeInterface
    {
        return match ($config->getRuntimeMode()) {
            'embedded' => new EmbeddedRuntime($this->runner, $this->logger, $this->filesystem),
            'custom' => new CustomRuntime($this->runner, $this->logger, $this->filesystem),
            'system' => new SystemRuntime($this->logger),
            'elephc' => new ElephcRuntime($this->runner, $this->logger, $this->filesystem),
            default => throw new InvalidArgumentException(\sprintf('Unknown runtime mode "%s".', $config->getRuntimeMode())),
        };
    }

    private function packager(BuildTarget $target): PackagerInterface
    {
        return match ($target->os) {
            'macos' => new MacosPackager($this->runner),
            'linux' => new LinuxPackager($this->runner),
            'windows' => new WindowsPackager($this->runner),
            'ios' => new IosPackager($this->runner),
            'android' => new AndroidPackager($this->runner),
            default => throw new InvalidArgumentException(\sprintf('Unknown target "%s".', $target->name)),
        };
    }

    /**
     * The command prefix that runs the builds of a target: none on its own system,
     * the configured runner for a Linux target built elsewhere.
     *
     * @return list<string>
     */
    private function prefix(AppConfig $config, string $target): array
    {
        $current = AppConfig::currentTarget();
        if ($target === $current) {
            return [];
        }
        [$os, $arch] = explode('-', $target, 2);
        if (('ios' === $os && str_starts_with($current, 'macos-')) || ('android' === $os && (str_starts_with($current, 'macos-') || str_starts_with($current, 'linux-')))) {
            return [];
        }
        // Any Windows builds both Windows targets: the host is published for each, and the
        // embedded PHP is x64 (static-php-cli builds no other), which Windows on Arm emulates.
        if ('windows' === $os && 'windows' === explode('-', $current)[0]) {
            return [];
        }
        if ('linux' === $os && null !== $runner = $config->getLinuxRunner($arch)) {
            return $runner;
        }
        if ('linux' === $os) {
            throw new RuntimeException(\sprintf('The %s target builds on Linux %s; this computer is %s. Set "build.hosts.linux_runners.%s" to a command that runs it there, like [orb, -m, <machine>].', $target, $arch, $current, $arch));
        }

        throw new RuntimeException(\sprintf('The %s target builds on a %s computer; this one is %s. Build it there, or in CI.', $target, $target, $current));
    }
}
