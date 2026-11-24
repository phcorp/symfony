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
use Symfony\Component\TuiApp\Exception\LogicException;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\Html;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;
use Symfony\Component\TuiHtml\Widget\DropdownWidget;

/**
 * Draws the form controls of an HTML view as native controls placed over the
 * canvas of its frames, where the CSS layout put them: the mappers of
 * Mapper\Html give the control, the renderer where it goes.
 *
 * @internal
 */
final class PlacedControlMapper implements WidgetMapperInterface
{
    /** The kinds of controls a host draws when it lists them in its capabilities, beyond those of canvas-controls */
    public const OPTIONAL_KINDS = [Html\PasswordMapper::KIND, Html\NumberMapper::KIND, Html\SliderMapper::KIND, Html\ListboxMapper::KIND];

    /** @var list<array{WidgetMapperInterface, string|null}> Each mapper, and the optional kind it maps to */
    private readonly array $mappers;
    /** @var array<string, true> The optional kinds the host draws */
    private array $hostKinds = [];

    /**
     * @param \Closure(AbstractWidget): (array<string, mixed>|null) $placement Where a widget is placed, null when it is not
     */
    public function __construct(
        private readonly \Closure $placement,
    ) {
        // A field of a kind the host does not draw is a text field, or stays in the canvas
        $this->mappers = [
            [new Html\PasswordMapper(), Html\PasswordMapper::KIND],
            [new Html\NumberMapper(), Html\NumberMapper::KIND],
            [new Html\InputMapper(), null],
            [new Html\TextareaMapper(), null],
            [new Html\CheckboxMapper(), null],
            [new Html\PopupMapper(), null],
            [new Html\ButtonMapper(), null],
            [new Html\SliderMapper(), Html\SliderMapper::KIND],
            [new Html\ListboxMapper(), Html\ListboxMapper::KIND],
        ];
    }

    /**
     * The optional kinds the host draws, from its capabilities.
     *
     * @param list<string> $capabilities
     */
    public function setHostCapabilities(array $capabilities): void
    {
        $this->hostKinds = array_fill_keys(array_intersect(self::OPTIONAL_KINDS, $capabilities), true);
    }

    /**
     * Whether a widget of an HTML view has a native control.
     */
    public function hasControl(AbstractWidget $widget): bool
    {
        return null !== $this->mapperOf($widget);
    }

    /**
     * Whether the control of a widget is a mark, like a checkbox, that takes the cells the widget draws, not its box.
     */
    public function isMark(AbstractWidget $widget): bool
    {
        return $this->mapperOf($widget) instanceof Html\CheckboxMapper;
    }

    /**
     * The widget that takes the focus for the control of a widget.
     */
    public static function focusTarget(AbstractWidget $widget): AbstractWidget
    {
        return $widget instanceof DropdownWidget ? $widget->getField() : $widget;
    }

    public function supports(AbstractWidget $widget): bool
    {
        return null !== ($this->placement)($widget) && null !== $this->mapperOf($widget);
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        $node = $this->mapperOf($widget)?->map($widget, $context) ?? throw new LogicException(\sprintf('No control for "%s".', get_debug_type($widget)));

        return new Node($node->kind, ($this->placement)($widget) + $node->props, $node->children);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
        $this->mapperOf($widget)?->handle($widget, $event);
    }

    private function mapperOf(AbstractWidget $widget): ?WidgetMapperInterface
    {
        foreach ($this->mappers as [$mapper, $kind]) {
            if (null !== $kind && !isset($this->hostKinds[$kind])) {
                continue;
            }
            if ($mapper->supports($widget)) {
                return $mapper;
            }
        }

        return null;
    }
}
