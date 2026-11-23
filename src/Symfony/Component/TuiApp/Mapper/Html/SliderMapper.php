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

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;
use Symfony\Component\TuiHtml\Widget\SliderWidget;

/**
 * Draws a range input of an HTML view as a native slider, between min and max
 * by step; one that CSS draws (appearance: none) stays drawn by the HTML
 * renderer.
 *
 * @internal
 */
final class SliderMapper implements WidgetMapperInterface
{
    public const KIND = 'slider';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof SliderWidget && $widget->hasNativeAppearance();
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof SliderWidget);

        return new Node(self::KIND, [
            'value' => $widget->getValue(),
            'min' => $widget->getMin(),
            'max' => $widget->getMax(),
            'step' => $widget->getStep(),
            'label' => $widget->getLabel(),
            'disabled' => $widget->hasState(AbstractWidget::STATE_DISABLED) ?: null,
        ]);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        \assert($widget instanceof SliderWidget);

        if ('change' !== $event->name || (!\is_int($event->value) && !\is_float($event->value))) {
            return;
        }
        // Like moving it with the keys: listeners get a ChangeEvent, the value snapped to a step
        $widget->setValue((float) $event->value, true);
    }
}
