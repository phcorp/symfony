<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Mapper;

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\CancellableLoaderWidget;
use Symfony\Component\Tui\Widget\LoaderWidget;

/**
 * Draws a LoaderWidget as a native spinner, with a Cancel button when it can be cancelled.
 *
 * @experimental
 */
final class LoaderMapper implements WidgetMapperInterface
{
    public const KIND = 'spinner';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof LoaderWidget;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof LoaderWidget);

        return new Node(self::KIND, $context->getCommonProps($widget) + [
            'text' => $widget->getMessage(),
            'running' => $widget->isRunning(),
            'cancellable' => $widget instanceof CancellableLoaderWidget,
        ]);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        if ('cancel' === $event->name && $widget instanceof CancellableLoaderWidget) {
            $event->press($widget, 'select_cancel');
        }
    }
}
