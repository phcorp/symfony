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

use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;

/**
 * Draws a SelectListWidget as a native list.
 *
 * @experimental
 */
final class SelectListMapper implements WidgetMapperInterface
{
    public const KIND = 'list';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof SelectListWidget;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof SelectListWidget);
        $items = $widget->getVisibleItems();
        $props = $context->getCommonProps($widget) + [
            'items' => array_map(static fn (array $item): array => ['label' => $item['label'], 'description' => $item['description'] ?? null], $items),
            'selected' => $items ? $widget->getSelectedIndex() : null,
            'multiple' => $widget->isMultiselect(),
            'checked' => array_keys(array_filter($items, static fn (array $item): bool => $item['checked'] ?? false)),
        ];
        if ($widget->isVerticallyExpanded()) {
            $props['grow'] ??= 1;
        }

        return new Node(self::KIND, $props);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        \assert($widget instanceof SelectListWidget);

        if ('cancel' === $event->name) {
            $event->press($widget, 'select_cancel');

            return;
        }
        if ('select' !== $event->name) {
            return;
        }

        $items = $widget->getVisibleItems();
        if (\is_int($event->value)) {
            if ($event->value < 0 || $event->value >= \count($items)) {
                return;
            }
            // Like moving to it with the keys, then Enter
            if ($event->value !== $widget->getSelectedIndex()) {
                $widget->setSelectedIndex($event->value);
                $widget->dispatch(new SelectionChangeEvent($widget, $items[$event->value]));
            }
            $event->press($widget, 'select_confirm');

            return;
        }

        if (!\is_array($event->value) || !$widget->isMultiselect()) {
            return;
        }

        // Toggle each item whose checkbox changed, the way Space does, then highlight the same item as before
        $wanted = array_flip(array_filter($event->value, \is_int(...)));
        $highlighted = $widget->getSelectedIndex();
        foreach ($items as $index => $item) {
            if (($item['checked'] ?? false) !== isset($wanted[$index])) {
                $widget->setSelectedIndex($index);
                $event->press($widget, 'choice_toggle');
            }
        }
        $widget->setSelectedIndex($highlighted);
    }
}
