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
use Symfony\Component\Tui\Widget\CollapsibleWidget;

/**
 * Draws a CollapsibleWidget as a disclosure.
 *
 * @experimental
 */
final class CollapsibleMapper implements WidgetMapperInterface
{
    public const KIND = 'disclosure';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof CollapsibleWidget;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof CollapsibleWidget);

        return new Node(self::KIND, $context->getCommonProps($widget) + [
            'summary' => $widget->getSummary(),
            'description' => $widget->getDescription(),
            'expanded' => $widget->isExpanded(),
        ], array_values($widget->all()));
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        \assert($widget instanceof CollapsibleWidget);

        if ('toggle' === $event->name && \is_bool($event->value)) {
            $event->value ? $widget->expand() : $widget->collapse();
        }
    }
}
