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
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * Draws any widget as styled text on a grid of cells, the way it draws in a
 * terminal. It is the fallback for widgets without a native control, like
 * MarkdownWidget: the host sends their key presses as keys.
 *
 * @experimental
 */
final class CanvasMapper implements WidgetMapperInterface
{
    public const KIND = 'canvas';

    public function supports(AbstractWidget $widget): bool
    {
        return true;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        $drawn = $context->renderLines($widget);
        $props = array_intersect_key($context->getCommonProps($widget), ['hidden' => true, 'disabled' => true, 'grow' => true, 'label' => true]);

        return new Node(self::KIND, $props + [
            'lines' => $drawn['lines'],
            'columns' => $drawn['columns'],
            'cursor' => $drawn['cursor'],
            'focusable' => $widget instanceof FocusableInterface,
        ]);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
    }
}
