<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Config;

use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;
use Symfony\Component\TuiApp\Exception\LogicException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the configuration of a desktop application without a kernel, from
 * a tui_app.yaml file or a tui_app.php file returning an array.
 *
 * Both hold the tui_app options at their root, under a tui_app key, or under
 * framework.tui_app, like the configuration of a Symfony application. Their
 * strings may use %env(NAME)% and %kernel.project_dir%.
 *
 * @experimental
 */
final class ConfigLoader
{
    public const FILES = ['tui_app.yaml', 'tui_app.yml', 'tui_app.php', 'config/tui_app.yaml', 'config/tui_app.yml', 'config/tui_app.php'];

    /**
     * Loads a file, or the first of FILES found in the project directory.
     */
    public function load(string $projectDir, ?string $file = null): AppConfig
    {
        if (null === $file) {
            foreach (self::FILES as $candidate) {
                if (is_file($projectDir.'/'.$candidate)) {
                    $file = $projectDir.'/'.$candidate;
                    break;
                }
            }
        }

        $config = null === $file ? [] : $this->resolve($this->read($file), $projectDir);

        return $this->process($config, $projectDir);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function process(array $config, string $projectDir): AppConfig
    {
        if (!class_exists(Processor::class)) {
            throw new LogicException('Configuring a desktop application requires the Config component. Try running "composer require symfony/config".');
        }

        return new AppConfig((new Processor())->processConfiguration(new Configuration(), [$config]), $projectDir);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $file): array
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException(\sprintf('The configuration file "%s" does not exist.', $file));
        }

        $config = match (strtolower(pathinfo($file, \PATHINFO_EXTENSION))) {
            'yaml', 'yml' => class_exists(Yaml::class)
                ? $this->parseYaml($file)
                : throw new LogicException('Reading a YAML configuration requires the Yaml component. Try running "composer require symfony/yaml".'),
            'php' => (static fn (string $file): mixed => require $file)($file),
            default => throw new InvalidArgumentException(\sprintf('The configuration file "%s" must be a .yaml or a .php file.', $file)),
        };

        if (null === $config) {
            return [];
        }
        if (!\is_array($config)) {
            throw new InvalidArgumentException(\sprintf('The configuration file "%s" must hold the tui_app options.', $file));
        }

        return match (true) {
            \is_array($config['framework']['tui_app'] ?? null) => $config['framework']['tui_app'],
            \is_array($config['tui_app'] ?? null) => $config['tui_app'],
            default => $config,
        };
    }

    private function parseYaml(string $file): mixed
    {
        try {
            return Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new InvalidArgumentException(\sprintf('The configuration file "%s" is not valid YAML: %s', $file, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Replaces %env(NAME)% and %kernel.project_dir% in the strings of a configuration.
     */
    private function resolve(mixed $value, string $projectDir): mixed
    {
        if (\is_array($value)) {
            return array_map(fn (mixed $value): mixed => $this->resolve($value, $projectDir), $value);
        }
        if (!\is_string($value)) {
            return $value;
        }

        return preg_replace_callback('/%%|%([^%\s]+)%/', static function (array $match) use ($value, $projectDir): string {
            if ('%%' === $match[0]) {
                return '%';
            }
            if ('kernel.project_dir' === $match[1]) {
                return $projectDir;
            }
            if (!preg_match('/^env\(([A-Za-z_][A-Za-z0-9_]*)\)$/D', $match[1], $env)) {
                throw new InvalidArgumentException(\sprintf('"%s" cannot be resolved without a kernel: only %%env(NAME)%% and %%kernel.project_dir%% can be used in "%s".', $match[0], $value));
            }
            $resolved = $_SERVER[$env[1]] ?? $_ENV[$env[1]] ?? getenv($env[1]);
            if (!\is_scalar($resolved) || false === $resolved) {
                throw new InvalidArgumentException(\sprintf('The environment variable "%s" is not set; "%s" needs it.', $env[1], $value));
            }

            return (string) $resolved;
        }, $value);
    }
}
