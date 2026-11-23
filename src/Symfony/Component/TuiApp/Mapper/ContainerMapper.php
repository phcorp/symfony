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

use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Draws a ContainerWidget as a stack of its children.
 *
 * @experimental
 */
final class ContainerMapper implements WidgetMapperInterface
{
    public const KIND = 'stack';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof ContainerWidget;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof ContainerWidget);
        $style = $context->getStyle($widget);
        $props = $context->getCommonProps($widget) + [
            'direction' => ($style->getDirection() ?? Direction::Vertical)->value,
            'gap' => $style->getGap() ?? 0,
        ];
        if ($widget->isVerticallyExpanded()) {
            $props['grow'] ??= 1;
        }

        return new Node(self::KIND, $props, array_values($widget->all()));
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
    }
}
