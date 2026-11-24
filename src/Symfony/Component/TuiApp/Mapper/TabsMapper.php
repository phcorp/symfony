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
use Symfony\Component\Tui\Widget\TabItem;
use Symfony\Component\Tui\Widget\TabsWidget;

/**
 * Draws a TabsWidget as native tabs.
 *
 * Only the active tab has its content in the widget tree: the other panes
 * are empty until shown.
 *
 * @experimental
 */
final class TabsMapper implements WidgetMapperInterface
{
    public const KIND = 'tabs';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof TabsWidget;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof TabsWidget);
        $active = $widget->getActiveTabIndex();
        $panes = [];
        foreach ($widget->getTabs() as $index => $tab) {
            $panes[] = $index === $active ? $tab->getContent() : new Node(ContainerMapper::KIND);
        }

        return new Node(self::KIND, $context->getCommonProps($widget) + [
            'tabs' => array_map(static fn (TabItem $tab): string => $tab->getLabel(), $widget->getTabs()),
            'active' => $active,
            'position' => $widget->getPosition()->value,
        ], $panes);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        \assert($widget instanceof TabsWidget);

        if ('tab' === $event->name && \is_int($event->value)) {
            $widget->setActiveTab($event->value);
        }
    }
}
