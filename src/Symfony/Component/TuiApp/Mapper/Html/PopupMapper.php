<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Mapper\Html;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;
use Symfony\Component\TuiHtml\Widget\DropdownWidget;

/**
 * Draws a <select> without size nor multiple, a dropdown, as a native pop-up
 * button: its list opens natively, never in the canvas; one that CSS draws
 * (appearance: none) stays drawn by the HTML renderer.
 *
 * @internal
 */
final class PopupMapper implements WidgetMapperInterface
{
    public const KIND = 'popup';

    public function supports(AbstractWidget $widget): bool
    {
        // A native pop-up has no <optgroup> headings: a select with groups stays drawn with them
        return $widget instanceof DropdownWidget && $widget->hasNativeAppearance()
            && !array_any($widget->getList()->getItems(), static fn (array $item): bool => isset($item['group']));
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof DropdownWidget);
        $list = $widget->getList();
        // What the filter shows, like the list: indexes are those of the items it shows
        $items = $list->getVisibleItems();

        return new Node(self::KIND, [
            'choices' => array_map(static fn (array $item): string => AnsiUtils::stripAnsiCodes($item['label']), $items),
            'selected' => isset($items[$index = $list->getSelectedIndex()]) ? $index : null,
            'label' => $widget->getLabel() ?? $widget->getField()->getLabel(),
            'disabled' => self::isDisabled($widget) ?: null,
        ]);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        \assert($widget instanceof DropdownWidget);
        $list = $widget->getList();

        if ('select' !== $event->name || !\is_int($event->value)) {
            return;
        }
        if (self::isDisabled($widget) || !isset($list->getVisibleItems()[$event->value])) {
            return;
        }
        // Like choosing it in the open list: Enter on it, which listeners of the choice get
        $list->setSelectedIndex($event->value);
        $event->press($list, 'select_confirm');
    }

    private static function isDisabled(DropdownWidget $widget): bool
    {
        return $widget->hasState(AbstractWidget::STATE_DISABLED) || $widget->getField()->hasState(AbstractWidget::STATE_DISABLED);
    }
}
