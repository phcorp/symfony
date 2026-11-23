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

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\TuiApp\Mapper\CanvasMapper;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;

/**
 * Draws the whole widget tree as one canvas, with the frames of a renderer
 * that lays the widgets out its own way, like the HTML renderer of TuiHtml:
 * native controls laid out by the host would lose that layout. The form
 * controls are native ones placed over the canvas where the frame has them,
 * when the host can place them.
 *
 * The canvas asks for Tab and Shift+Tab, which move the focus the way the
 * renderer orders it, like in a browser.
 *
 * @internal
 */
final class FrameMapper implements WidgetMapperInterface
{
    /** The keys the canvas and its controls send rather than use */
    public const KEYS = ['tab', 'shift+tab'];

    /**
     * @param \Closure(ContainerWidget): list<string>         $renderFrame The lines of the frame shown in the window
     * @param \Closure(ContainerWidget): list<AbstractWidget> $controls    The widgets drawn as native controls over the frame just drawn, in focus order
     */
    public function __construct(
        private readonly \Closure $renderFrame,
        private readonly \Closure $controls,
    ) {
    }

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof ContainerWidget && null === $widget->getParent();
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof ContainerWidget);
        $lines = [];
        $cursor = null;
        foreach (($this->renderFrame)($widget) as $row => $line) {
            $parsed = AnsiRuns::parse($line);
            $lines[] = $parsed['runs'];
            if (null !== $parsed['cursor']) {
                $cursor = [$row, $parsed['cursor']];
            }
        }

        return new Node(CanvasMapper::KIND, [
            'lines' => $lines,
            'columns' => $context->getColumns($widget),
            'cursor' => $cursor,
            'focusable' => true,
            'grow' => 1,
            'label' => $widget->getLabel(),
            'keys' => self::KEYS,
        ], ($this->controls)($widget));
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
    }
}
