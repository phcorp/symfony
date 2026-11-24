<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Fixtures;

use Symfony\Component\Tui\Render\FrameWriterInterface;
use Symfony\Component\Tui\Render\LineBufferInterface;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\RendererInterface;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * A renderer that draws with another one, without saying so (no RendererDecoratorInterface).
 */
class WrappingRenderer implements RendererInterface
{
    public function __construct(
        protected readonly RendererInterface $inner,
    ) {
    }

    public function addStyleSheet(StyleSheet $styleSheet): void
    {
        $this->inner->addStyleSheet($styleSheet);
    }

    public function resolveElement(AbstractWidget $widget, string $element): Style
    {
        return $this->inner->resolveElement($widget, $element);
    }

    public function invalidateStyles(): void
    {
        $this->inner->invalidateStyles();
    }

    public function resolveStyle(AbstractWidget $widget): Style
    {
        return $this->inner->resolveStyle($widget);
    }

    public function createFrameWriter(TerminalInterface $terminal): FrameWriterInterface
    {
        return $this->inner->createFrameWriter($terminal);
    }

    public function getWidgetRect(AbstractWidget $widget): ?WidgetRect
    {
        return $this->inner->getWidgetRect($widget);
    }

    public function renderFrame(ContainerWidget $root, int $columns, int $rows): LineBufferInterface
    {
        return $this->inner->renderFrame($root, $columns, $rows);
    }

    public function renderWidgetLines(AbstractWidget $widget, RenderContext $context): LineBufferInterface
    {
        return $this->inner->renderWidgetLines($widget, $context);
    }
}
