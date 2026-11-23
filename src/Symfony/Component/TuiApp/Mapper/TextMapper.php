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
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Draws a TextWidget as a label.
 *
 * @experimental
 */
final class TextMapper implements WidgetMapperInterface
{
    public const KIND = 'label';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof TextWidget;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof TextWidget);
        $text = $widget->getText();
        $props = $context->getCommonProps($widget);
        if (null !== $align = $context->getStyle($widget)->getTextAlign()) {
            $props['align'] = $align->value;
        }

        return new Node(self::KIND, $props + ($context->isStyled($text) ? ['runs' => $context->toRuns($text)] : ['text' => $text]));
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
    }
}
