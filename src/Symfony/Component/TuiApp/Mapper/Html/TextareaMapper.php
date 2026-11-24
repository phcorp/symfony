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
use Symfony\Component\TuiHtml\Widget\EditorWidget;

/**
 * Draws a <textarea> of an HTML view as a native text area; a read-only one,
 * and one that CSS draws (appearance: none, a border of the page), stay drawn
 * by the HTML renderer.
 *
 * @internal
 */
final class TextareaMapper implements WidgetMapperInterface
{
    public const KIND = 'textarea';

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof EditorWidget && $widget->hasNativeAppearance() && !$widget->isReadOnly();
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        \assert($widget instanceof EditorWidget);

        return new Node(self::KIND, [
            'value' => $widget->getText(),
            'label' => $widget->getLabel(),
            'disabled' => $widget->hasState(AbstractWidget::STATE_DISABLED) ?: null,
            'maxLength' => $widget->getMaxLength(),
        ]);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        \assert($widget instanceof EditorWidget);

        TextControl::handle($widget, $event, $widget->getText(...), $widget->setText(...), $widget->getMaxLength());
    }
}
