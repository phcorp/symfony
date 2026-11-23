<?php

return function (Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container) {
    $container->services()->alias('test_tui_app_config', 'tui_app.config')->public();
    $container->extension('framework', [
        'tui_app' => [
            'app' => [
                'name' => 'Acme Notes',
                'id' => 'com.acme.notes',
                'version' => '%env(TUI_APP_VERSION)%',
            ],
            'runtime' => [
                'mode' => 'system',
            ],
        ],
    ]);
};
