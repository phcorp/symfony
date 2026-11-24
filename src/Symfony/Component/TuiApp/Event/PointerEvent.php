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
 * Dispatched when the mouse or the trackpad acts on a canvas, from a host that sends it: a press or a release of a
 * button, a move, the pointer leaving the canvas, or the wheel.
 *
 * Stopping its propagation keeps what follows from happening: an HTML view does not take it (no hover, click or
 * scroll), and the right button opens no context menu.
 *
 * @experimental
 */
final class PointerEvent extends Event
{
    /**
     * @param 'press'|'release'|'move'|'leave'|'wheel' $action
     * @param 'left'|'middle'|'right'|null             $button
     * @param list<string>                             $modifiers The modifier keys held: ctrl, shift, alt, super
     */
    public function __construct(
        private readonly ?AbstractWidget $widget,
        private readonly string $action,
        private readonly int $row,
        private readonly int $column,
        private readonly ?string $button = null,
        private readonly array $modifiers = [],
        private readonly int $rows = 0,
        private readonly int $columns = 0,
    ) {
    }

    /**
     * The widget under the pointer: the deepest one of an HTML view drawn there, else the one the canvas draws.
     */
    public function getWidget(): ?AbstractWidget
    {
        return $this->widget;
    }

    /**
     * @return 'press'|'release'|'move'|'leave'|'wheel'
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * The row of the cell under the pointer, from the top of the canvas.
     */
    public function getRow(): int
    {
        return $this->row;
    }

    /**
     * The column of the cell under the pointer, from the left of the canvas.
     */
    public function getColumn(): int
    {
        return $this->column;
    }

    /**
     * @return 'left'|'middle'|'right'|null The button pressed or released, or held during a move
     */
    public function getButton(): ?string
    {
        return $this->button;
    }

    /**
     * @return list<string>
     */
    public function getModifiers(): array
    {
        return $this->modifiers;
    }

    /**
     * The rows the wheel scrolls by, down (negative: up).
     */
    public function getScrollRows(): int
    {
        return $this->rows;
    }

    /**
     * The columns the wheel scrolls by, right (negative: left).
     */
    public function getScrollColumns(): int
    {
        return $this->columns;
    }
}
