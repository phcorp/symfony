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

use Symfony\Component\Tui\Ansi\AnsiCodeTracker;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Color;

/**
 * Splits a line of ANSI text into runs of text with one style each, the way
 * the host protocol carries styled text.
 *
 * Only SGR sequences matter, read by the code tracker of Tui. The cursor marker gives the cursor column;
 * other sequences (hyperlinks, images) are dropped.
 *
 * @internal
 */
final class AnsiRuns
{
    /**
     * @return array{runs: list<array<string, string|bool>>, cursor: int|null}
     */
    public static function parse(string $line): array
    {
        $runs = [];
        $style = [];
        $text = '';
        $cursor = null;
        $tracker = new AnsiCodeTracker();

        foreach (AnsiUtils::walkCells($line) as $token) {
            if (0 !== $token['width'] || !str_starts_with($token['text'], "\x1b")) {
                $text .= $token['text'];
                continue;
            }

            if (str_starts_with($token['text'], AnsiUtils::CURSOR_MARKER_PREFIX)) {
                $cursor = $token['col'];
                continue;
            }

            if (!str_starts_with($token['text'], "\x1b[") || !str_ends_with($token['text'], 'm')) {
                continue;
            }

            $tracker->process($token['text']);
            $next = self::styleOf($tracker);
            if ($next !== $style) {
                if ('' !== $text) {
                    $runs[] = ['t' => $text] + $style;
                    $text = '';
                }
                $style = $next;
            }
        }

        if ('' !== $text) {
            $runs[] = ['t' => $text] + $style;
        }

        return ['runs' => $runs, 'cursor' => $cursor];
    }

    /**
     * @return array<string, string|bool>
     */
    private static function styleOf(AnsiCodeTracker $tracker): array
    {
        return array_filter([
            'b' => $tracker->isBold(),
            'bg' => self::color($tracker->getBackground()),
            'd' => $tracker->isDim(),
            'fg' => self::color($tracker->getForeground()),
            'i' => $tracker->isItalic(),
            'r' => $tracker->isInverse(),
            's' => $tracker->isStrikethrough(),
            'u' => $tracker->isUnderline(),
        ]);
    }

    /**
     * The hex color of SGR color parameters, like 31, 38;5;9, 38;2;255;0;0 or 38:2::255:0:0.
     */
    private static function color(?string $parameters): ?string
    {
        if (null === $parameters) {
            return null;
        }

        $codes = array_map(intval(...), preg_split('/[;:]/', $parameters) ?: []);
        if (!\in_array($codes[0], [38, 48], true)) {
            return (Color::fromSgrForeground($codes[0]) ?? Color::fromSgrBackground($codes[0]))?->toHex();
        }
        if (5 === $codes[1]) {
            return Color::palette(max(0, min(255, $codes[2])))->toHex();
        }
        // 38:2::R:G:B has a color space id before the components
        $rgb = array_map(static fn (int $c): int => max(0, min(255, $c)), \array_slice($codes, -3));

        return Color::rgb(...$rgb)->toHex();
    }
}
