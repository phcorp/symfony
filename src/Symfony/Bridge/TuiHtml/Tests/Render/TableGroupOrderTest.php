<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\TuiHtml\Tests\Render;

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\TuiHtml\Css\CssStyleSheet;
use Symfony\Bridge\TuiHtml\Html\HtmlBuilder;
use Symfony\Bridge\TuiHtml\Internal\StyleBridge;
use Symfony\Bridge\TuiHtml\Render\LayoutEngine;
use Symfony\Bridge\TuiHtml\Render\PositionTracker;
use Symfony\Bridge\TuiHtml\Render\Renderer;
use Symfony\Bridge\TuiHtml\Style\Style;
use Symfony\Bridge\TuiHtml\Style\TableRole;
use Symfony\Bridge\TuiHtml\Widget\TextWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Widget\Figlet\FontRegistry;

final class TableGroupOrderTest extends TestCase
{
    public function testCaptionsAndSourceKeysSurviveReordering()
    {
        $roles = ['before' => null, 'footer' => 1, 23 => 0, 'header' => -1, 'after' => null];
        $children = [];
        foreach ($roles as $key => $place) {
            $child = new TextWidget((string) $key);
            $style = new Style();
            if (null !== $place) {
                $style = $style->withTableRole(0 === $place ? TableRole::Row : TableRole::RowGroup)->withRowGroupPlace($place);
            }
            StyleBridge::set($child, $style);
            $children[$key] = $child;
        }
        $engine = new LayoutEngine(new Renderer(), new PositionTracker(), new FontRegistry());
        $ordered = $engine->tableOrder($children);
        self::assertSame(['before', 'header', 23, 'footer', 'after'], array_keys($ordered));
        foreach ($ordered as $key => $child) {
            self::assertSame($children[$key], $child);
        }
    }

    public function testFirstHeaderAndFooterDetermineRenderedOrder()
    {
        $sheet = new CssStyleSheet();
        $view = new HtmlBuilder($sheet)->build('<table><caption>CAPTION</caption><tfoot><tr><td>FOOTONE</td></tr></tfoot><thead><tr><td>HEADONE</td></tr></thead><tbody><tr><td>BODY</td></tr></tbody><thead><tr><td>HEADTWO</td></tr></thead><tfoot><tr><td>FOOTTWO</td></tr></tfoot></table>');
        $renderer = new Renderer();
        $renderer->getStyleSheet()->addProvider($sheet);
        $text = AnsiUtils::stripAnsiCodes(implode("\n", $renderer->renderFrame($view->getRoot(), 40, 20)->toArray()));
        $previous = -1;
        foreach (['CAPTION', 'HEADONE', 'BODY', 'HEADTWO', 'FOOTTWO', 'FOOTONE'] as $label) {
            $position = strpos($text, $label);
            self::assertNotFalse($position, $label);
            self::assertGreaterThan($previous, $position, $label);
            $previous = $position;
        }
    }
}
