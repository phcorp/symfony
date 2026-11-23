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

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\RendererInterface;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\TuiApp\Render\AnsiRuns;

/**
 * What a mapper needs to map a widget: its style, and its lines when it
 * draws as text.
 *
 * @experimental
 */
final class MapContext
{
    /**
     * @param \Closure(AbstractWidget): int $columnsFor
     */
    public function __construct(
        private readonly RendererInterface $textRenderer,
        private readonly \Closure $columnsFor,
        private readonly int $rows,
    ) {
    }

    /**
     * The style the stylesheets give the widget.
     */
    public function getStyle(AbstractWidget $widget): Style
    {
        return $this->textRenderer->resolveStyle($widget);
    }

    /**
     * The props every kind has, from the widget's style and state.
     *
     * @return array<string, mixed>
     */
    public function getCommonProps(AbstractWidget $widget): array
    {
        $style = $this->getStyle($widget);
        $textStyle = array_filter([
            'fg' => $style->getColor()?->toHex(),
            'bg' => $style->getBackground()?->toHex(),
            'bold' => $style->getBold() ?: null,
            'dim' => $style->getDim() ?: null,
            'italic' => $style->getItalic() ?: null,
            'underline' => $style->getUnderline() ?: null,
            'strike' => $style->getStrikethrough() ?: null,
        ], static fn (mixed $value): bool => null !== $value);
        $padding = $style->getPadding();
        $border = $style->getBorder();

        return array_filter([
            'style' => $textStyle ?: null,
            'hidden' => $style->getHidden() ?: null,
            'disabled' => $widget->hasState(AbstractWidget::STATE_DISABLED) ?: null,
            'focusable' => $widget instanceof FocusableInterface ? true : null,
            'grow' => $style->getFlex() ?: null,
            'padding' => null !== $padding && $padding->top + $padding->right + $padding->bottom + $padding->left > 0 ? [$padding->top, $padding->right, $padding->bottom, $padding->left] : null,
            'border' => null !== $border && $border->getHorizontal() + $border->getVertical() > 0 ?: null,
            'label' => $widget->getLabel(),
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * Splits text that may hold ANSI styles into runs.
     *
     * @return list<array<string, string|bool>>
     */
    public function toRuns(string $text): array
    {
        $runs = [];
        foreach (explode("\n", $text) as $i => $line) {
            if ($i > 0) {
                $runs[] = ['t' => "\n"];
            }
            array_push($runs, ...AnsiRuns::parse($line)['runs']);
        }

        return $runs;
    }

    /**
     * Whether text holds ANSI escape sequences, and needs runs.
     */
    public function isStyled(string $text): bool
    {
        return AnsiUtils::stripAnsiCodes($text) !== $text;
    }

    /**
     * The width the host gave the widget in the last frame, in cells.
     */
    public function getColumns(AbstractWidget $widget): int
    {
        return ($this->columnsFor)($widget);
    }

    /**
     * Draws the widget as text, the way it draws in a terminal, with its padding and border.
     *
     * @return array{lines: list<list<array<string, string|bool>>>, cursor: array{int, int}|null, columns: int}
     */
    public function renderLines(AbstractWidget $widget): array
    {
        $columns = max(1, $this->getColumns($widget));
        $lines = [];
        $cursor = null;
        foreach ($this->textRenderer->renderWidgetLines($widget, new RenderContext($columns, $this->rows)) as $row => $line) {
            $parsed = AnsiRuns::parse($line);
            $lines[] = $parsed['runs'];
            if (null !== $parsed['cursor']) {
                $cursor = [$row, $parsed['cursor']];
            }
        }

        return ['lines' => $lines, 'cursor' => $cursor, 'columns' => $columns];
    }
}
