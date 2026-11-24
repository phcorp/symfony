<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Command;

use Psr\Log\LogLevel;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\TuiApp\Build\Builder;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Config\ConfigLoader;
use Symfony\Component\TuiApp\Config\Configuration;
use Symfony\Component\TuiApp\Exception\ExceptionInterface;

/**
 * Builds the desktop packages of the application: an app bundle and a disk
 * image on macOS, deb, rpm and tar packages on Linux, an installer on Windows.
 *
 * @experimental
 */
#[AsCommand(name: 'tui-app:build', description: 'Build the desktop or mobile packages of the application')]
final class BuildCommand extends Command
{
    /**
     * @param AppConfig|null $config The configuration, from framework.tui_app; null reads it from tui_app.yaml or tui_app.php
     */
    public function __construct(
        private readonly ?AppConfig $config = null,
        private readonly ?string $projectDir = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('target', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, \sprintf('A target to build, among those configured (%s)', implode(', ', Configuration::TARGETS)))
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'A tui_app.yaml or tui_app.php file, when the application has no kernel')
            ->addOption('dump-manifest', null, InputOption::VALUE_REQUIRED, 'Print the manifest of a target and stop')
            ->setHelp(<<<'HELP'
                The <info>%command.name%</info> command builds the packages of the application for the targets
                configured in <comment>build.targets</comment>, by default the system of this computer:

                  <info>php %command.full_name%</info>
                  <info>php %command.full_name% --target=linux-x86_64 --target=linux-arm64</info>

                A macOS or iOS target builds on macOS and a Windows target on Windows. Android
                targets build on macOS or Linux with the Android SDK and NDK. Linux targets
                build anywhere their packaging tools are. The packages are written to
                <comment>build.output</comment>, one directory per target.


                HELP.(null === $this->config
                    ? 'Without a kernel, the configuration is read from <comment>tui_app.yaml</comment> or <comment>tui_app.php</comment>, at the root of the project or in <comment>config/</comment>, or from the file given with <info>--config</info>.'
                    : 'See the configuration with <info>config:dump-reference framework tui_app</info>.'))
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null !== $this->config && null !== $input->getOption('config')) {
            $io->getErrorStyle()->warning('"--config" is ignored: the application is configured under "framework.tui_app".');
        }

        try {
            $config = $this->config ?? (new ConfigLoader())->load($this->projectDir ?? getcwd() ?: '.', $input->getOption('config'));

            if (null !== $target = $input->getOption('dump-manifest')) {
                if (!\in_array($target, Configuration::TARGETS, true)) {
                    $io->error(\sprintf('"%s" is not a target; use one of %s.', $target, implode(', ', Configuration::TARGETS)));

                    return Command::FAILURE;
                }
                $output->writeln(json_encode($config->toManifest($target), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

                return Command::SUCCESS;
            }

            $targets = $input->getOption('target') ?: null;
            $io->title(\sprintf('Building %s %s for %s', $config->getName(), $config->getVersion(), implode(', ', $targets ?? $config->getTargets())));

            $logger = new ConsoleLogger($output, [LogLevel::NOTICE => OutputInterface::VERBOSITY_NORMAL, LogLevel::INFO => OutputInterface::VERBOSITY_NORMAL, LogLevel::DEBUG => OutputInterface::VERBOSITY_VERBOSE]);
            $packages = (new Builder($logger))->build($config, $targets);
        } catch (ExceptionInterface|InvalidConfigurationException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(['Built:', ...$packages]);

        return Command::SUCCESS;
    }
}
