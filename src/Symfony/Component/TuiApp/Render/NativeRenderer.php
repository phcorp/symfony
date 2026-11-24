<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Render;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Focus\FocusPolicyInterface;
use Symfony\Component\Tui\Render\FrameWriterInterface;
use Symfony\Component\Tui\Render\LineBufferInterface;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\RendererDecoratorInterface;
use Symfony\Component\Tui\Render\RendererInterface;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\TuiApp\Application;
use Symfony\Component\TuiApp\Exception\LogicException;
use Symfony\Component\TuiApp\Input\KeyEncoder;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\MapperRegistry;
use Symfony\Component\TuiApp\Protocol\HostConnection;
use Symfony\Component\TuiApp\Terminal\HostTerminal;
use Symfony\Component\TuiHtml\Render\Renderer as HtmlRenderer;
use Symfony\Component\TuiHtml\Widget\ImageMapWidget;
use Symfony\Component\TuiHtml\Widget\InputWidget as HtmlInputWidget;
use Symfony\Component\TuiHtml\Widget\LinkedTextWidget;
use Symfony\Component\TuiHtml\Widget\LinksInterface;

/**
 * Draws the widget tree with the native controls of a host window.
 *
 * Each widget is mapped to a node (a text field, a list, tabs...) by the
 * mappers; widgets without a native control draw as styled text on a
 * canvas. Every frame sends the host what changed since the last one, and
 * what the user does on a control is applied to its widget.
 *
 * Styles are resolved by the renderer it decorates, the native renderer of
 * Tui by default, which also draws canvases. When that renderer is the HTML
 * renderer of TuiHtml, which lays the widgets out its own way, or decorates it
 * (RendererDecoratorInterface), the whole tree is one canvas drawn with its
 * frames, and it decides which widgets take the focus when it is a focus
 * policy; with any other renderer, the mappers draw the widgets. Tab and Shift+Tab move the focus there,
 * like in a browser, and the form controls of an HTML view are native ones
 * placed over the canvas, when the host can place them (canvas-controls).
 *
 * @experimental
 */
final class NativeRenderer implements RendererDecoratorInterface, FocusPolicyInterface
{
    private readonly RendererInterface $textRenderer;
    private readonly NodeTree $tree;
    /** The mappers of the nodes of the tree: those given, or those of the canvas of the frames */
    private readonly MapperRegistry $treeMappers;
    private readonly PlacedControlMapper $controls;
    /** Whether the host places native controls over the canvas of the frames */
    private bool $placesControls = false;
    /** @var \WeakMap<AbstractWidget, array<string, mixed>> Where the controls over the canvas of the frames are */
    private \WeakMap $placements;
    /** @var \WeakMap<TerminalInterface, \Closure> The terminals whose Tab moves the focus, and their listener */
    private \WeakMap $tabbing;
    /** Whether the renderer decorated draws the tree, as one canvas, rather than the mappers */
    private readonly bool $drawsFrames;
    /** The HTML renderer that draws the frames, which tells what it draws over the controls */
    private readonly ?HtmlRenderer $htmlRenderer;
    private ?HostConnection $connection = null;
    /** @var array<string, \Closure> What the renderer takes from the host */
    private array $hostListeners = [];
    /** @var array<int, array{int, int, int, int}> */
    private array $rects = [];
    /** @var array<int, int> The columns each canvas was drawn for */
    private array $canvasColumns = [];
    private int|false|null $hostFocus = false;
    private ?ContainerWidget $root = null;
    private int $columns = 80;
    private int $rows = 24;
    private int $scrollOffset = 0;
    /** The first line of the frame the window shows */
    private int $frameTop = 0;
    /** The lines of the frame the window does not show, below and above what it shows */
    private int $frameHidden = 0;
    /** @var array{int, int}|null The columns and rows of the canvas of the frames, once the host drew it */
    private ?array $frameRect = null;
    /** @var list<string> The lines of the frame the window shows */
    private array $frameLines = [];

    public function __construct(
        ?RendererInterface $textRenderer = null,
        private readonly MapperRegistry $mappers = new MapperRegistry(),
    ) {
        $this->textRenderer = $textRenderer ?? new Renderer();
        // The HTML renderer lays the widgets out its own way, which native controls laid out by the host would lose;
        // with any other renderer, like the native one or one wrapping it, the mappers draw them
        $html = $this->textRenderer;
        while (!$html instanceof HtmlRenderer && $html instanceof RendererDecoratorInterface) {
            $html = $html->getDecoratedRenderer();
        }
        $this->drawsFrames = $html instanceof HtmlRenderer;
        $this->htmlRenderer = $html instanceof HtmlRenderer ? $html : null;
        $this->placements = new \WeakMap();
        $this->tabbing = new \WeakMap();
        $this->controls = new PlacedControlMapper(fn (AbstractWidget $widget): ?array => $this->placements[$widget] ?? null);
        $this->treeMappers = $this->drawsFrames ? new MapperRegistry([new FrameMapper($this->drawFrame(...), $this->placeControls(...)), $this->controls]) : $this->mappers;
        $this->tree = new NodeTree($this->treeMappers);
    }

    public function getDecoratedRenderer(): RendererInterface
    {
        return $this->textRenderer;
    }

    public function addStyleSheet(StyleSheet $styleSheet): void
    {
        $this->textRenderer->addStyleSheet($styleSheet);
    }

    public function resolveElement(AbstractWidget $widget, string $element): Style
    {
        return $this->textRenderer->resolveElement($widget, $element);
    }

    public function invalidateStyles(): void
    {
        $this->textRenderer->invalidateStyles();
    }

    public function resolveStyle(AbstractWidget $widget): Style
    {
        return $this->textRenderer->resolveStyle($widget);
    }

    public function createFrameWriter(TerminalInterface $terminal): FrameWriterInterface
    {
        if (!$terminal instanceof HostTerminal) {
            throw new LogicException(\sprintf('The native renderer draws in the window of a TuiApp host, through a "%s"; "%s" given. Start the application with "%s".', HostTerminal::class, get_debug_type($terminal), Application::class));
        }

        $connection = $terminal->getConnection();
        if ($this->connection !== $connection) {
            $this->detach();
            $this->forgetTree();
            $this->connection = $connection;
            $this->placesControls = $this->drawsFrames && $connection->hasCapability('canvas-controls');
            $this->controls->setHostCapabilities($connection->getCapabilities());
        }

        return new HostFrameWriter($connection, $this, $terminal);
    }

    /**
     * Takes what the host sends and the keys typed, once the renderer draws: Tui may still refuse it after creating
     * its writer.
     *
     * @internal
     */
    public function attach(HostTerminal $terminal): void
    {
        if (null === $this->connection) {
            return;
        }
        if (!$this->hostListeners) {
            $this->hostListeners = ['event' => $this->onEvent(...), 'rects' => $this->onRects(...)];
            foreach ($this->hostListeners as $type => $listener) {
                $this->connection->on($type, $listener);
            }
        }
        // After the listeners of the application, which may take Tab for themselves
        if ($this->drawsFrames && !isset($this->tabbing[$terminal])) {
            $terminal->getEventDispatcher()->addListener(InputEvent::class, $this->tabbing[$terminal] = $this->onInput(...), -100);
        }
    }

    /**
     * Stops taking what the host sends and the keys typed, once another renderer draws in its place.
     *
     * @internal
     */
    public function detach(): void
    {
        $this->detachFromHost();
        $this->connection = null;
        foreach ($this->tabbing as $terminal => $listener) {
            $terminal->getEventDispatcher()->removeListener(InputEvent::class, $listener);
        }
        $this->tabbing = new \WeakMap();
    }

    public function getWidgetRect(AbstractWidget $widget): ?WidgetRect
    {
        if ($this->drawsFrames) {
            // Where the frame has the widget, in the lines the canvas shows
            $id = null === $this->root ? null : $this->tree->getId($this->root);
            $canvas = null === $id ? [0, 0] : $this->rects[$id] ?? [0, 0];

            return $this->textRenderer->getWidgetRect($widget)?->withOffset($canvas[0] - $this->frameTop, $canvas[1]);
        }

        $id = $this->tree->getId($widget);
        if (null === $id || !isset($this->rects[$id])) {
            return null;
        }

        [$row, $col, $columns, $rows] = $this->rects[$id];

        return new WidgetRect($row, $col, $columns, $rows);
    }

    public function renderFrame(ContainerWidget $root, int $columns, int $rows): LineBufferInterface
    {
        $this->root = $root;
        $this->columns = $columns;
        $this->rows = $rows;
        $context = new MapContext($this->textRenderer, $this->drawsFrames ? fn (): int => $this->getFrameSize()[0] : $this->columnsFor(...), $rows);

        $ops = $this->tree->sync($root, $context);
        // What the host sent of the nodes it no longer has
        $drawn = fn (int $id): bool => null !== $this->tree->getKind($id);
        $this->rects = array_filter($this->rects, $drawn, \ARRAY_FILTER_USE_KEY);
        $this->canvasColumns = array_filter($this->canvasColumns, $drawn, \ARRAY_FILTER_USE_KEY);
        // The canvas of the frames is drawn again when the window is resized
        foreach ($this->drawsFrames ? [] : $ops as $op) {
            if ('canvas' === ($op['kind'] ?? null) || isset($op['props']->columns)) {
                $this->canvasColumns[$op['id']] = $op['props']->columns ?? $this->canvasColumns[$op['id']] ?? $columns;
            }
        }

        $focus = $this->focusedId($root);
        if ($focus === $this->hostFocus) {
            $focus = false;
        } else {
            $this->hostFocus = $focus;
        }

        return new PatchFrame($ops, $focus);
    }

    public function renderWidgetLines(AbstractWidget $widget, RenderContext $context): LineBufferInterface
    {
        return $this->textRenderer->renderWidgetLines($widget, $context);
    }

    public function allowsFocus(AbstractWidget $widget): bool
    {
        if ($this->textRenderer instanceof FocusPolicyInterface) {
            return $this->textRenderer->allowsFocus($widget);
        }

        // Not drawn yet, or drawn: the host decides what takes the focus once it is drawn
        for ($current = $widget; null !== $current; $current = $current->getParent()) {
            if ($this->resolveStyle($current)->getHidden()) {
                return false;
            }
        }

        return true;
    }

    public function orderFocusCycle(array $widgets): array
    {
        if ($this->textRenderer instanceof FocusPolicyInterface) {
            return $this->textRenderer->orderFocusCycle($widgets);
        }

        return $widgets;
    }

    /**
     * The HTML renderer of TuiHtml that draws the frames, the renderer decorated or one it decorates, if any.
     */
    public function getHtmlRenderer(): ?HtmlRenderer
    {
        return $this->htmlRenderer;
    }

    /**
     * Returns the widget a node stands for, if any.
     *
     * @internal
     */
    public function getWidget(int $nodeId): ?AbstractWidget
    {
        return $this->tree->getWidget($nodeId);
    }

    /**
     * @internal
     */
    public function getNodeId(AbstractWidget $widget): ?int
    {
        return $this->tree->getId($widget);
    }

    private function detachFromHost(): void
    {
        foreach ($this->hostListeners as $type => $listener) {
            $this->connection?->off($type, $listener);
        }
        $this->hostListeners = [];
    }

    /**
     * Applies a host event to its widget, with the mapper that drew its node.
     *
     * The control already shows what the user did: the next frame sends it
     * what the widget keeps when that is something else, like a disabled
     * widget, which takes no event.
     *
     * @param array<string, mixed> $message
     */
    private function onEvent(array $message): void
    {
        $id = $message['node'] ?? null;
        $name = $message['name'] ?? null;
        if (!\is_int($id) || !\is_string($name) || null === $widget = $this->tree->getWidget($id)) {
            return;
        }

        if ('focus' === $name) {
            $this->hostFocus = $id;
            $focusManager = $widget->getContext()?->getFocusManager();
            if ($this->drawsFrames && $widget instanceof ContainerWidget && $widget === $this->root) {
                // The canvas itself: a control over it that had the focus loses it, like a field clicked out of
                if ($this->focusedId($widget) !== $id) {
                    $focusManager?->setFocus(null);
                }
            } elseif (($target = $this->drawsFrames ? PlacedControlMapper::focusTarget($widget) : $widget) instanceof FocusableInterface) {
                $focusManager?->setFocus($target);
            }
        } else {
            $event = new HostEvent($name, $message['value'] ?? null);
            if (!$widget->hasState(AbstractWidget::STATE_DISABLED)) {
                ($this->tree->getMapper($id) ?? $this->treeMappers->get($widget))->handle($widget, $event);
            }
            if ($acknowledged = HostControlState::acknowledge($this->tree->getKind($id), $this->tree->getProps($id), $event) + $event->getAcknowledged()) {
                $this->tree->acknowledge($id, $acknowledged);
            }
        }

        $widget->getContext()?->requestRender();
    }

    /**
     * Records where the host drew the nodes, and draws canvases again at their new width.
     *
     * @param array<string, mixed> $message
     */
    private function onRects(array $message): void
    {
        $rects = $message['rects'] ?? null;
        if (!\is_array($rects)) {
            return;
        }

        $resized = false;
        foreach ($rects as $id => $rect) {
            if (!\is_array($rect) || 4 !== \count($rect)) {
                continue;
            }
            $id = (int) $id;
            $rect = array_values($rect);
            $this->rects[$id] = [(int) $rect[0], (int) $rect[1], (int) $rect[2], (int) $rect[3]];
            if (isset($this->canvasColumns[$id]) && $this->canvasColumns[$id] !== $this->rects[$id][2] && null !== $widget = $this->tree->getWidget($id)) {
                $widget->invalidate();
                $resized = true;
            }
        }

        // The canvas of the frames is a bit smaller than the window, whose size the host may send after: the host
        // sends the rects again each time it lays the window out
        $id = null === $this->root ? null : $this->tree->getId($this->root);
        if ($this->drawsFrames && null !== $id && isset($rects[$id], $this->rects[$id])) {
            $size = [max(1, $this->rects[$id][2]), max(1, $this->rects[$id][3])];
            if ($size !== $this->frameRect) {
                $this->frameRect = $size;
                $this->root?->invalidate();
                $resized = true;
            }
        }

        if ($resized) {
            $this->root?->getContext()?->requestRender();
        }
    }

    /**
     * The node tree the host has, serialized like the contract's session fixture.
     *
     * @return array<string, mixed>|null
     *
     * @internal
     */
    public function dumpTree(): ?array
    {
        return $this->tree->dump();
    }

    /**
     * @internal
     */
    public function forgetTree(): ?int
    {
        $this->rects = [];
        $this->canvasColumns = [];
        $this->frameRect = null;
        $this->frameLines = [];
        $this->frameTop = 0;
        $this->frameHidden = 0;
        $this->placements = new \WeakMap();
        $this->hostFocus = false;

        return $this->tree->reset();
    }

    /**
     * @internal
     */
    public function setScrollOffset(int $offset): void
    {
        if ($this->scrollOffset !== $offset = max(0, $offset)) {
            $this->scrollOffset = $offset;
            // The canvas of the frames shows another part of the frame
            if ($this->drawsFrames) {
                $this->root?->getContext()?->requestRender();
            }
        }
    }

    /**
     * A cell of a canvas, where Tui::getWidgetRect() has the widgets drawn there: null but for the canvas of the frames.
     *
     * @internal
     *
     * @return array{int, int}|null
     */
    public function getFrameCell(int $nodeId, int $row, int $col): ?array
    {
        if (!$this->drawsFrames || null === $this->root || $nodeId !== $this->tree->getId($this->root)) {
            return null;
        }
        $canvas = $this->rects[$nodeId] ?? [0, 0];

        return [$canvas[0] + $row, $canvas[1] + $col];
    }

    /**
     * The scroll offset that shows the frame some rows further down (up when negative), as far as there is frame to
     * show; null when it shows as far already.
     *
     * @internal
     */
    public function scrollFrameBy(int $rows): ?int
    {
        $offset = max(0, min($this->frameHidden, $this->scrollOffset) - $rows);
        $offset = min($offset, $this->frameHidden);

        return $offset === min($this->frameHidden, $this->scrollOffset) ? null : $offset;
    }

    /**
     * Tab and Shift+Tab from the canvas of the frames, or a control over it, move the focus, like in a browser; they
     * are left to the widget that takes them: a field with a completion to take with the cursor at its end, like one
     * with suggestions or a file field, and text with links, or an image map, going to its next or previous link;
     * Shift+Tab comes into one on its last link.
     */
    private function onInput(InputEvent $event): void
    {
        $step = match ($event->getData()) {
            KeyEncoder::encode('tab') => 1,
            KeyEncoder::encode('shift+tab'), "\x1b[Z" => -1,
            default => 0,
        };
        if (0 === $step || null === $context = $this->root?->getContext()) {
            return;
        }
        $focused = $context->getFocusManager()->getFocus();
        if (1 === $step && $focused instanceof HtmlInputWidget && !$focused->isReadOnly() && null !== $focused->getCompletion() && $focused->getCursor() === \strlen($focused->getValue())) {
            return;
        }
        [$link, $links] = match (true) {
            $focused instanceof LinkedTextWidget => [$focused->getCurrentLink() ?? 0, \count($focused->getLinks())],
            $focused instanceof ImageMapWidget => [$focused->getCurrentArea() ?? 0, \count($focused->getHrefs())],
            default => [0, 0],
        };
        if (($focused instanceof LinkedTextWidget || $focused instanceof ImageMapWidget) && (1 === $step ? $link < $links - 1 : $link > 0)
            && $focused->getKeybindings()->matches($event->getData(), 1 === $step ? 'next_link' : 'previous_link')) {
            return;
        }
        $event->stopPropagation();
        if (1 === $step) {
            $context->getFocusManager()->focusNext();
        } elseif (($previous = $context->getFocusManager()->focusPrevious()) instanceof LinksInterface) {
            // Into text with links, or an image map, from after it: on its last link, like in a browser
            $previous->focusLastLink();
        }
        $context->requestRender();
    }

    /**
     * The widgets of the frame just drawn that are native controls over its canvas, in the order of the focus, and
     * where each one is.
     *
     * @return list<AbstractWidget>
     */
    private function placeControls(ContainerWidget $root): array
    {
        $this->placements = new \WeakMap();
        if (!$this->placesControls) {
            return [];
        }

        $controls = [];
        $find = function (AbstractWidget $widget) use (&$find, &$controls): void {
            if ($this->controls->hasControl($widget)) {
                $controls[] = $widget;
            } elseif ($widget instanceof ParentInterface) {
                foreach ($widget->all() as $child) {
                    $find($child);
                }
            }
        };
        $find($root);

        // Those that take the focus in their order, then the others (disabled...) in the order of the document
        $cycle = array_flip(array_map(spl_object_id(...), $root->getContext()?->getFocusManager()->getCycle() ?? []));
        $order = [];
        foreach ($controls as $index => $control) {
            $order[$index] = $cycle[spl_object_id(PlacedControlMapper::focusTarget($control))] ?? \count($cycle) + $index;
        }
        asort($order);

        $placed = [];
        foreach (array_keys($order) as $index) {
            $control = $controls[$index];
            $this->placements[$control] = $this->placement(PlacedControlMapper::focusTarget($control), $this->controls->isMark($control));
            $placed[] = $control;
        }

        return $placed;
    }

    /**
     * Where the frame just drawn has a widget, in cells of its canvas: its place, and what is shown of it when it is
     * cut by the window, or a scroll container; hidden when none of it is shown, or the renderer does not let it take
     * the focus (inert, invisible).
     *
     * @param bool $mark Whether the widget draws a mark, like a checkbox: placed where the frame has the mark, a row
     *                   tall, without the blank cells around it its box may have, as a stretched cell of a grid
     *
     * @return array<string, mixed>
     */
    private function placement(AbstractWidget $widget, bool $mark = false): array
    {
        $rect = $this->textRenderer->getWidgetRect($widget);
        if (null === $rect || ($this->textRenderer instanceof FocusPolicyInterface && !$this->textRenderer->allowsFocus($widget))) {
            return ['hidden' => true];
        }

        [$columns, $rows] = $this->getFrameSize();
        $row = $rect->row - $this->frameTop;
        $place = [$row, $rect->col, $rect->columns, $rect->rows];
        // The row of the mark, a box taller than it, like a stretched or centered cell of a grid, being blank around it
        for ($line = max(0, $row); $mark && $line < $row + $rect->rows && isset($this->frameLines[$line]); ++$line) {
            $cells = AnsiUtils::stripAnsiCodes(AnsiUtils::sliceByColumn($this->frameLines[$line], $rect->col, $rect->columns));
            if ('' !== $drawn = trim($cells, ' ')) {
                $place = [$line, $rect->col + \strlen($cells) - \strlen(ltrim($cells, ' ')), AnsiUtils::visibleWidth($drawn), 1];
                break;
            }
        }
        // First row and column, then the row and column past
        $shown = [max(0, $place[0]), max(0, $place[1]), min($rows, $place[0] + $place[3]), min($columns, $place[1] + $place[2])];
        if (null !== $clip = $rect->clip) {
            $shown = [max($shown[0], $clip[0] - $this->frameTop), max($shown[1], $clip[1]), min($shown[2], $clip[2] - $this->frameTop), min($shown[3], $clip[3])];
        }
        if ($shown[0] >= $shown[2] || $shown[1] >= $shown[3]) {
            return ['place' => $place, 'hidden' => true];
        }
        // Under what the renderer draws over it, like the open list of a dropdown, a popover or a dialog, which the
        // native control, drawn over the canvas, would hide: hidden then; only what is shown of it, cut by a scroll
        // container or another box that clips it
        foreach ($this->htmlRenderer?->getRectsDrawnOver($widget) ?? [] as $over) {
            $drawn = [$over->row - $this->frameTop, $over->col, $over->row - $this->frameTop + $over->rows, $over->col + $over->columns];
            if (null !== $overClip = $over->clip) {
                $drawn = [max($drawn[0], $overClip[0] - $this->frameTop), max($drawn[1], $overClip[1]), min($drawn[2], $overClip[2] - $this->frameTop), min($drawn[3], $overClip[3])];
            }
            if ($drawn[0] < $shown[2] && $drawn[2] > $shown[0] && $drawn[1] < $shown[3] && $drawn[3] > $shown[1]) {
                return ['place' => $place, 'hidden' => true];
            }
        }
        $clip = [$shown[0], $shown[1], $shown[3] - $shown[1], $shown[2] - $shown[0]];

        return ['place' => $place, 'clip' => $clip === $place ? null : $clip];
    }

    /**
     * Draws the frame of the renderer decorated, and keeps what the window shows of it.
     *
     * @return list<string>
     */
    private function drawFrame(ContainerWidget $root): array
    {
        [$columns, $rows] = $this->getFrameSize();
        $lines = $this->textRenderer->renderFrame($root, $columns, $rows);
        $hidden = \count($lines) - $rows;
        $this->frameHidden = max(0, $hidden);
        if ($hidden <= 0) {
            $this->frameTop = 0;

            return $this->frameLines = $lines->toArray();
        }

        // Like a terminal: the bottom of the frame, moved up by the scroll offset
        $this->frameTop = $hidden - min($this->scrollOffset, $hidden);

        return $this->frameLines = $lines->slice($this->frameTop, $rows);
    }

    /**
     * The columns and rows of the canvas of the frames: those of the window until the host drew it.
     *
     * @return array{int, int}
     */
    private function getFrameSize(): array
    {
        return $this->frameRect ?? [$this->columns, $this->rows];
    }

    private function columnsFor(AbstractWidget $widget): int
    {
        $id = $this->tree->getId($widget);

        return null !== $id && isset($this->rects[$id]) ? max(1, $this->rects[$id][2]) : $this->columns;
    }

    private function focusedId(ContainerWidget $root): ?int
    {
        if ($this->drawsFrames) {
            // The control over the canvas that has the focus, else the canvas, which takes every key, like a terminal
            $focused = $root->getContext()?->getFocusManager()->getFocus();
            for ($widget = $focused; null !== $widget && $widget !== $root; $widget = $widget->getParent()) {
                $id = $this->tree->getId($widget);
                if (null !== $id && $this->tree->getWidget($id) === $widget && $id !== $this->tree->getId($root)) {
                    return ($this->placements[$widget]['hidden'] ?? false) ? $this->tree->getId($root) : $id;
                }
            }

            return $this->tree->getId($root);
        }

        $focused = $root->getContext()?->getFocusManager()->getFocus();

        return null === $focused ? null : $this->tree->getId($focused);
    }
}
