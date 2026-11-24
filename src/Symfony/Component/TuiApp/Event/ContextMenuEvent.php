<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Event;

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when the right button is pressed on a canvas of a host that shows context menus, with the items of the
 * menu to show there: those a browser would show, like Open Link and Copy Link Address over a link of an HTML view, or
 * Paste over a field. A listener adds its own, changes them, or removes them all: no menu is shown without items.
 *
 * @experimental
 */
final class ContextMenuEvent extends Event
{
    /**
     * @param list<ContextMenuItem> $items
     */
    public function __construct(
        private readonly ?AbstractWidget $widget,
        private readonly int $row,
        private readonly int $column,
        private array $items = [],
    ) {
    }

    /**
     * The widget under the pointer, see PointerEvent::getWidget().
     */
    public function getWidget(): ?AbstractWidget
    {
        return $this->widget;
    }

    public function getRow(): int
    {
        return $this->row;
    }

    public function getColumn(): int
    {
        return $this->column;
    }

    /**
     * @return list<ContextMenuItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @param list<ContextMenuItem> $items
     */
    public function setItems(array $items): void
    {
        $this->items = $items;
    }

    public function addItem(ContextMenuItem $item): void
    {
        $this->items[] = $item;
    }
}
