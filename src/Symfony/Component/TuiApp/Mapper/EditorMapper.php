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
use Symfony\Component\Tui\Widget\EditorWidget;

/**
 * Draws an EditorWidget as a multi-line text area.
 *
 * @experimental
 */
final class EditorMapper implements WidgetMapperInterface
{
    public const KIND = 'textarea';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof EditorWidget;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof EditorWidget);
        $props = $context->getCommonProps($widget) + ['value' => $widget->getText()];
        if ($widget->isVerticallyExpanded()) {
            $props['grow'] ??= 1;
        }

        return new Node(self::KIND, $props);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        \assert($widget instanceof EditorWidget);

        TextControl::handle($widget, $event, $widget->getText(...), $widget->setText(...));
    }
}
