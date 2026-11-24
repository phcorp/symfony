<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\TuiApp\Application;
use Symfony\Component\TuiApp\Config\AppConfig;

return static function (ContainerConfigurator $container) {
    $container->services()
        ->set('tui_app.config', AppConfig::class)
            ->args([
                param('.tui_app.config'),
                param('kernel.project_dir'),
            ])
        ->alias(AppConfig::class, 'tui_app.config')

        // Connects to the host that started the process, if any, when first used
        ->set('tui_app.application', Application::class)
            ->lazy()
            ->args([
                null,
                tagged_iterator('tui_app.widget_mapper'),
            ])
        ->alias(Application::class, 'tui_app.application')
    ;
};
