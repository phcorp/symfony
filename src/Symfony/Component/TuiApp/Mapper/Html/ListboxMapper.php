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
use Symfony\Component\TuiHtml\Event\SelectionChangeEvent;
use Symfony\Component\TuiHtml\Widget\CompositeControlInterface;
use Symfony\Component\TuiHtml\Widget\DropdownWidget;
use Symfony\Component\TuiHtml\Widget\SelectListWidget;

/**
 * Draws a <select> with size or multiple of an HTML view as a native list:
 * one row selected, or several for multiple. One that CSS draws
 * (appearance: none) stays drawn by the HTML renderer, so does one with
 * <optgroup> headings, which a native list has not, and a list that is a
 * part of another control, like the suggestions of a field, which a native
 * one would take the focus from.
 *
 * @internal
 */
final class ListboxMapper implements WidgetMapperInterface
{
    public const KIND = 'listbox';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof SelectListWidget && $widget->hasNativeAppearance() && !$widget->getParent() instanceof DropdownWidget && !$widget->getParent() instanceof CompositeControlInterface
            && !array_any($widget->getItems(), static fn (array $item): bool => isset($item['group']));
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof SelectListWidget);
        // What the filter shows, like the list: indexes are those of the items it shows
        $items = $widget->getVisibleItems();

        return new Node(self::KIND, [
            'choices' => array_map(static fn (array $item): string => AnsiUtils::stripAnsiCodes($item['label']), $items),
            'selected' => self::selected($widget),
            'multiple' => $widget->isMultiselect() ?: null,
            'label' => $widget->getLabel(),
            'disabled' => $widget->hasState(AbstractWidget::STATE_DISABLED) ?: null,
        ]);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        \assert($widget instanceof SelectListWidget);

        if ('select' !== $event->name || !\is_array($event->value) || !array_is_list($event->value)) {
            return;
        }
        $visible = $widget->getVisibleItems();
        $wanted = array_values(array_unique(array_filter($event->value, static fn (mixed $index): bool => \is_int($index) && isset($visible[$index]))));

        if (!$widget->isMultiselect()) {
            // Like moving to it with the keys: listeners get a SelectionChangeEvent
            if ([] !== $wanted && ($index = $wanted[0]) !== $widget->getSelectedIndex()) {
                $widget->setSelectedIndex($index);
                if (null !== $item = $widget->getSelectedItem()) {
                    $widget->dispatch(new SelectionChangeEvent($widget, $item));
                }
            }

            return;
        }

        // Each item whose state changed is toggled the way Space does, the last one staying highlighted
        $checked = array_flip($wanted);
        $highlighted = $widget->getSelectedIndex();
        foreach ($visible as $index => $choice) {
            if (($choice['checked'] ?? false) !== isset($checked[$index])) {
                $widget->setSelectedIndex($index);
                $event->press($widget, 'choice_toggle');
                $highlighted = $index;
            }
        }
        $widget->setSelectedIndex($highlighted);
    }

    /**
     * @return list<int>
     */
    private static function selected(SelectListWidget $widget): array
    {
        $visible = $widget->getVisibleItems();
        if (!$widget->isMultiselect()) {
            return isset($visible[$index = $widget->getSelectedIndex()]) ? [$index] : [];
        }

        return array_keys(array_filter($visible, static fn (array $item): bool => $item['checked'] ?? false));
    }
}
