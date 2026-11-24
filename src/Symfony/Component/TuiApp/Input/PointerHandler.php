<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Input;

use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\TuiApp\Event\ContextMenuEvent;
use Symfony\Component\TuiApp\Event\ContextMenuItem;
use Symfony\Component\TuiApp\Event\PointerEvent;
use Symfony\Component\TuiApp\Protocol\HostConnection;
use Symfony\Component\TuiApp\Render\NativeRenderer;
use Symfony\Component\TuiHtml\Html\HtmlView;
use Symfony\Component\TuiHtml\Widget\EditorWidget as HtmlEditorWidget;
use Symfony\Component\TuiHtml\Widget\InputWidget as HtmlInputWidget;

/**
 * Handles what the mouse does on the canvases (pointer messages). It dispatches a PointerEvent first, and with the right
 * button a ContextMenuEvent, whose items it shows (contextmenu messages). Then it gives it to the HTML views drawn on
 * the canvas of the frames, the way a browser does: a press makes what is under it :active, a release on what was
 * pressed clicks it (focuses it, presses a button, toggles a box, follows a link), a move hovers and drags a slider
 * pressed, and the wheel scrolls the scroll container under the pointer, else the frame the window shows the bottom
 * of, like a terminal. The host shows the CSS cursor of what is under the pointer (cursor messages).
 *
 * @internal
 */
final class PointerHandler
{
    /** @var array{HtmlView, AbstractWidget|null}|null The view a button was pressed on, and the widget pressed */
    private ?array $pressed = null;
    /** @var array{int, string}|null The canvas node and the cursor last sent for it */
    private ?array $cursor = null;
    /** Whether the last move dragged a slider */
    private bool $dragging = false;
    /** @var list<ContextMenuItem> The items of the context menu shown last, the id of each "<menu>:<its position from 1>" */
    private array $menu = [];
    /** The number of the context menu shown last, in the ids of its items: a select from another one does nothing */
    private int $menuNumber = 0;

    /**
     * @param Tui $tui A Tui drawn by a native renderer
     */
    public function __construct(
        private readonly Tui $tui,
        private readonly ?HostConnection $connection = null,
    ) {
    }

    /**
     * @param array<string, mixed> $message
     */
    public function handle(array $message): void
    {
        if (!\is_int($message['node'] ?? null) || !\is_int($message['row'] ?? null) || !\is_int($message['column'] ?? null)
            || !\in_array($action = $message['action'] ?? null, ['press', 'release', 'move', 'leave', 'wheel'], true)) {
            return;
        }
        $button = \in_array($message['button'] ?? null, ['left', 'middle', 'right'], true) ? $message['button'] : null;
        $renderer = $this->renderer();
        // The frames of an HTML renderer hold HTML views, which take what the pointer does
        $cell = null !== $renderer->getHtmlRenderer() ? $renderer->getFrameCell($message['node'], $message['row'], $message['column']) : null;
        $view = null !== $cell ? $this->viewAt(...$cell) : null;
        $widget = $view?->widgetAt(...$cell) ?? $renderer->getWidget($message['node']);
        $modifiers = array_values(array_filter((array) ($message['modifiers'] ?? []), \is_string(...)));
        $event = new PointerEvent($widget, $action, $message['row'], $message['column'], $button, $modifiers, (int) ($message['rows'] ?? 0), (int) ($message['columns'] ?? 0));
        // The menu shown before is not anymore, whatever this press shows
        if ('press' === $action && 'right' === $button) {
            $this->menu = [];
        }
        $this->tui->getEventDispatcher()->dispatch($event);
        if ($event->isPropagationStopped()) {
            return;
        }
        if ('press' === $action && 'right' === $button) {
            $this->openContextMenu($message['node'], $message['row'], $message['column'], $widget, $view, $cell);
        }
        if (null === $cell) {
            return;
        }
        [$row, $col] = $cell;
        $left = 'left' === $button;

        $done = match ($action) {
            'press' => $left && $this->press($row, $col),
            'release' => $left && $this->release($row, $col),
            'move' => $this->move($row, $col, $left),
            // Nothing is under the pointer anymore, nor pressed: a host sends it when a button is released off the canvas
            'leave' => $this->leave(),
            // wheel
            default => $this->wheel($row, $col, (int) ($message['rows'] ?? 0), (int) ($message['columns'] ?? 0), \in_array('shift', (array) ($message['modifiers'] ?? []), true)),
        };
        // What a scroll brought under the pointer: the frame drawn now, where the widgets are, for its cursor
        if ('wheel' === $action && $done) {
            $this->tui->processRender();
        }
        // A slider dragged keeps its cursor, like in a browser
        if (!$this->dragging) {
            $this->updateCursor($message['node'], 'leave' === $action ? null : $this->viewAt($row, $col)?->cursorAt($row, $col) ?? 'default');
        }
    }

    /**
     * The item of the context menu shown last the user chose (contextmenu-select): what it does.
     *
     * @param array<string, mixed> $message
     */
    public function select(array $message): void
    {
        // An item of another menu, which the host still showed: nothing
        if (!\is_string($id = $message['id'] ?? null) || !preg_match('/^(\d+):(\d+)$/', $id, $m) || (int) $m[1] !== $this->menuNumber) {
            return;
        }
        $item = $this->menu[(int) $m[2] - 1] ?? null;
        $this->menu = [];
        if (null !== $action = $item?->isEnabled() ? $item->getAction() : null) {
            $action();
            $this->tui->requestRender();
        }
    }

    /**
     * The menu of the right button: what a browser shows (Open Link and Copy Link Address over a link, Paste over a
     * field, focused like a browser does), as the listeners of the ContextMenuEvent leave it.
     *
     * @param array{int, int}|null $cell
     */
    private function openContextMenu(int $node, int $row, int $col, ?AbstractWidget $widget, ?HtmlView $view, ?array $cell): void
    {
        if (!$this->connection?->hasCapability('contextmenu')) {
            return;
        }
        $items = [];
        $field = null;
        // The link there now, followed wherever it is drawn once chosen
        if (null !== $view && null !== $cell && null !== [$text, $index] = $view->getLinkAt(...$cell)) {
            $items[] = new ContextMenuItem('Open Link', static function () use ($view, $text, $index): void {
                $view->followLink($text, $index);
            });
        }
        if (null !== $view && null !== $cell && null !== $href = $view->hrefAt(...$cell)) {
            if ($this->connection?->hasCapability('clipboard')) {
                $items[] = new ContextMenuItem('Copy Link Address', function () use ($href): void {
                    $this->connection?->send(['type' => 'clipboard', 'text' => $href]);
                });
            }
        } elseif (($widget instanceof HtmlInputWidget || $widget instanceof HtmlEditorWidget) && !$widget->hasState('disabled')) {
            $field = $widget;
            $items[] = new ContextMenuItem('Paste', role: 'paste', enabled: !$widget->hasState('readonly'));
        }
        // A field takes the focus with its menu, like in a browser: the listeners see it focused, and it goes back
        // when no menu is shown
        $focus = $this->tui->getFocus();
        if (null !== $field) {
            $this->tui->setFocus($field);
        }
        $event = new ContextMenuEvent($widget, $row, $col, $items);
        $this->tui->getEventDispatcher()->dispatch($event);
        ++$this->menuNumber;
        $sent = [];
        foreach ($event->getItems() as $item) {
            if ($item->isSeparator()) {
                $sent[] = ['separator' => true];
                continue;
            }
            $this->menu[] = $item;
            $sent[] = ['id' => $this->menuNumber.':'.\count($this->menu), 'label' => $item->getLabel()] + ($item->isEnabled() ? [] : ['enabled' => false]) + (null !== $item->getRole() ? ['role' => $item->getRole()] : []);
        }
        if (!$this->menu) {
            if (null !== $field && $this->tui->getFocus() === $field) {
                $this->tui->setFocus($focus);
            }

            return;
        }
        $this->connection?->send(['type' => 'contextmenu', 'node' => $node, 'row' => $row, 'column' => $col, 'items' => $sent]);
    }

    /**
     * Sends the cursor of what is under the pointer when it changed: the host keeps it while the pointer is over that
     * canvas, and goes back to its own once it left it.
     */
    private function updateCursor(int $node, ?string $shape): void
    {
        if (null === $shape) {
            $this->cursor = null;

            return;
        }
        if ([$node, $shape] !== $this->cursor) {
            $this->cursor = [$node, $shape];
            $this->connection?->send(['type' => 'cursor', 'node' => $node, 'shape' => $shape]);
        }
    }

    private function press(int $row, int $col): bool
    {
        // What was pressed before is not anymore, even when nothing is there
        if (null !== $this->pressed) {
            $this->pressed[0]->release();
            $this->pressed = null;
        }
        $this->dragging = false;
        if (null === $view = $this->viewAt($row, $col)) {
            return false;
        }
        $this->pressed = [$view, $view->press($row, $col)];

        return true;
    }

    private function release(int $row, int $col): bool
    {
        if (null === [$view, $widget] = $this->pressed) {
            return false;
        }
        $this->pressed = null;
        $this->dragging = false;
        $view->release();
        // Clicked when released on what was pressed, like in a browser
        if (null === $widget || $view->widgetAt($row, $col) !== $widget) {
            return false;
        }

        // Nothing else follows the links of a window: a click on one dispatches a LinkEvent, like Enter on it
        return $view->click($row, $col, followLinks: true);
    }

    private function move(int $row, int $col, bool $left): bool
    {
        // A slider pressed follows the pointer while the button is held, like in a browser, and keeps the hover
        $this->dragging = $left && null !== $this->pressed && $this->pressed[0]->drag($row, $col);

        return $this->dragging || $this->hover($row, $col);
    }

    private function leave(): bool
    {
        if (null !== $this->pressed) {
            $this->pressed[0]->release();
            $this->pressed = null;
        }
        $this->dragging = false;

        return $this->hover(-1, -1);
    }

    private function hover(int $row, int $col): bool
    {
        foreach (HtmlView::getMountedViews($this->tui) as $view) {
            $view->hover($row, $col);
        }

        return true;
    }

    private function wheel(int $row, int $col, int $rows, int $columns, bool $shift): bool
    {
        // Shift turns a vertical wheel sideways, like in a browser
        if ($shift && 0 === $columns) {
            [$rows, $columns] = [0, $rows];
        }
        foreach ($this->viewsAt($row, $col) as $view) {
            if ($view->scrollAt($row, $col, $rows, $columns)) {
                return true;
            }
        }
        // Nothing scrolls there: the window scrolls the frame, like a terminal its scrollback
        if (0 !== $rows && null !== $offset = $this->renderer()->scrollFrameBy($rows)) {
            $this->tui->setScrollOffset($offset);

            return true;
        }

        return false;
    }

    private function viewAt(int $row, int $col): ?HtmlView
    {
        return $this->viewsAt($row, $col)[0] ?? null;
    }

    /**
     * The views drawn at a cell, the one deepest in the tree first: a view mounted in a part of a page before it.
     *
     * @return list<HtmlView>
     */
    private function viewsAt(int $row, int $col): array
    {
        $views = [];
        foreach (HtmlView::getMountedViews($this->tui) as $view) {
            if (null === $view->widgetAt($row, $col)) {
                continue;
            }
            $depth = 0;
            for ($widget = $view->getRoot(); null !== $widget = $widget->getParent(); ++$depth) {
            }
            $views[] = [$depth, $view];
        }
        usort($views, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_column($views, 1);
    }

    private function renderer(): NativeRenderer
    {
        $renderer = $this->tui->getRenderer();
        \assert($renderer instanceof NativeRenderer);

        return $renderer;
    }
}
