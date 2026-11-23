<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp;

use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\ConsoleBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\AbstractBundle;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Kernel\ServicesBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\TuiApp\Config\Configuration;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;

/**
 * Configures the desktop application under tui_app (or framework.tui_app),
 * and provides its Application, its mappers and the tui-app:build command.
 *
 * Mappers of the application are services implementing WidgetMapperInterface.
 *
 * @experimental
 */
#[RequiredBundle(ServicesBundle::class)]
#[RequiredBundle(ConsoleBundle::class, ignoreOnInvalid: true)]
class TuiAppBundle extends AbstractBundle
{
    protected string $extensionAlias = 'tui_app';

    public function getPath(): string
    {
        return $this->path ??= __DIR__;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        Configuration::addOptions($definition->rootNode());
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $container->setParameter('.tui_app.config', $config);
        $configurator->import('Resources/config/tui_app.php');

        // The build command, when what building needs is there
        if (class_exists(ConsoleApplication::class) && class_exists(Process::class) && class_exists(Filesystem::class) && interface_exists(LoggerInterface::class)) {
            $configurator->import('Resources/config/console.php');
        }

        $container->registerForAutoconfiguration(WidgetMapperInterface::class)
            ->addTag('tui_app.widget_mapper');
    }
}
