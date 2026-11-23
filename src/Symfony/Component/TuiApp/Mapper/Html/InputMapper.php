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
use Symfony\Component\TuiApp\Mapper\TextControl;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;
use Symfony\Component\TuiHtml\Widget\CompositeControlInterface;
use Symfony\Component\TuiHtml\Widget\InputWidget;

/**
 * Draws a single-line field of an HTML view as a native text field.
 *
 * Fields the native one cannot stand for stay drawn by the HTML renderer: a
 * password (masked), a read-only field, one that completes what is typed with
 * Tab (suggestions, a file field), and one that is a part of another control,
 * like a field with a list of suggestions or a color.
 *
 * @internal
 */
final class InputMapper implements WidgetMapperInterface
{
    public const KIND = 'textfield';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof InputWidget && !$widget->isMasked() && !$widget->isReadOnly() && !$widget->isCompleting() && !$widget->getParent() instanceof CompositeControlInterface;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof InputWidget);

        return new Node(self::KIND, [
            'value' => $widget->getValue(),
            'label' => $widget->getLabel() ?? ('' !== $widget->getPlaceholder() ? $widget->getPlaceholder() : null),
            'disabled' => $widget->hasState(AbstractWidget::STATE_DISABLED) ?: null,
            'maxLength' => $widget->getMaxLength(),
        ]);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        \assert($widget instanceof InputWidget);

        TextControl::handle($widget, $event, $widget->getValue(...), $widget->setValue(...), $widget->getMaxLength());
    }
}
