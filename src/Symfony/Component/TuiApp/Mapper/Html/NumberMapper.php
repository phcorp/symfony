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
use Symfony\Component\TuiHtml\Widget\CompositeControlInterface;
use Symfony\Component\TuiHtml\Widget\InputWidget;

/**
 * Draws a number field of an HTML view as a native one with a stepper, which
 * Up and Down step too, by step within min and max; the text is sent as it is
 * typed, invalid or not, like a text field (see InputMapper), the field taking
 * it as a browser does: invalid, its :invalid state set.
 *
 * @internal
 */
final class NumberMapper implements WidgetMapperInterface
{
    public const KIND = 'number';

    private readonly InputMapper $field;

    public function __construct()
    {
        $this->field = new InputMapper();
    }

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof InputWidget && null !== $widget->getNumberRange() && !$widget->isMasked() && !$widget->isReadOnly() && !$widget->isCompleting() && !$widget->getParent() instanceof CompositeControlInterface;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof InputWidget);
        $range = $widget->getNumberRange() ?? ['min' => null, 'max' => null, 'step' => 1.0];

        return new Node(self::KIND, [
            'value' => $widget->getValue(),
            'min' => $range['min'],
            'max' => $range['max'],
            'step' => $range['step'],
            'label' => $widget->getLabel() ?? ('' !== $widget->getPlaceholder() ? $widget->getPlaceholder() : null),
            'disabled' => $widget->hasState(AbstractWidget::STATE_DISABLED) ?: null,
        ]);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        $this->field->handle($widget, $event);
    }
}
