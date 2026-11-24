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

use Symfony\Component\TuiApp\Command\BuildCommand;

return static function (ContainerConfigurator $container) {
    $container->services()
        ->set('console.command.tui_app_build', BuildCommand::class)
            ->args([
                service('tui_app.config'),
                param('kernel.project_dir'),
            ])
            ->tag('console.command')
    ;
};
