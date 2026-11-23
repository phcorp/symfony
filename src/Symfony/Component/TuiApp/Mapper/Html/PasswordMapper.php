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
 * Draws a password field of an HTML view as a native secure text field, which
 * shows bullets and keeps what is typed out of the pasteboard; edited like a
 * text field (see InputMapper), maxlength included.
 *
 * @internal
 */
final class PasswordMapper implements WidgetMapperInterface
{
    public const KIND = 'password';

    private readonly InputMapper $field;

    public function __construct()
    {
        $this->field = new InputMapper();
    }

    public function supports(AbstractWidget $widget): bool
    {
        return $widget instanceof InputWidget && $widget->isMasked() && !$widget->isReadOnly() && !$widget->isCompleting() && !$widget->getParent() instanceof CompositeControlInterface;
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
        $this->field->handle($widget, $event);
    }
}
