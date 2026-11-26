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
use Symfony\Bridge\TuiHtml\Html\HtmlBuilder;
use Symfony\Bridge\TuiHtml\Render\FacadeRenderer;
use Symfony\Bridge\TuiHtml\Tests\Compatibility\FacadeScenario;
use Symfony\Bridge\TuiHtml\Tests\Compatibility\TerminalCellSnapshot;
use Symfony\Component\Tui\Render\Renderer;

class FacadeInlineImageGroupTest extends TestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAPUlEQVRYhe3VsQ3AQAwDMSn77+wfQkUaqj+AcONechnWqU6+Ld8HAAAAAAAA0OTGj94u9e8XAAAAAAAAAHghNAU9l77oWAAAAABJRU5ErkJggg==';

    public function testPrefixAndFinalRemainderMatchNativeCells()
    {
        foreach ([3, 4, 5, 8, 12] as $width) {
            foreach ([1, 2] as $dpi) {
                $html = '<p style="width:'.$width.'ch">A <a href="https://example.org"><img src="'.self::PNG.'" width="64" height="16" alt="wide"></a> Z</p>';
                $options = ['columns' => 30, 'rows' => 20, 'dppx' => $dpi];
                $old = FacadeScenario::render($html, $options);
                $new = FacadeScenario::render($html, $options, true);
                $plain = static fn ($text) => \Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes(str_replace('⟨', "\x1b[", $text));
                self::assertSame($plain($old), $plain($new));
                self::assertSame(TerminalCellSnapshot::normalize($old, 30, 20, [229, 229, 229]), TerminalCellSnapshot::normalize($new, 30, 20, [229, 229, 229]));
            }
        }
    }

    public function testProgrammaticCellsAreNotLimitedByTheWholeImageUserAgentCap()
    {
        $old = $this->programmaticFrame(false);
        $new = $this->programmaticFrame(true);
        self::assertSame(TerminalCellSnapshot::normalize($old, 30, 20, [229, 229, 229]), TerminalCellSnapshot::normalize($new, 30, 20, [229, 229, 229]));
    }

    public function testExplicitEmergencyAndBreakAllModesMatchRetainedCells()
    {
        foreach (['overflow-wrap:anywhere', 'overflow-wrap:break-word', 'overflow-wrap:normal;word-break:break-all'] as $policy) {
            $html = '<p style="width:5ch;'.$policy.'">A <a href="https://example.org"><img src="'.self::PNG.'" width="64" height="16" alt="wide"></a> Z</p>';
            $options = ['columns' => 30, 'rows' => 20];
            $old = FacadeScenario::render($html, $options);
            $new = FacadeScenario::render($html, $options, true);
            self::assertSame(TerminalCellSnapshot::normalize($old, 30, 20, [229, 229, 229]), TerminalCellSnapshot::normalize($new, 30, 20, [229, 229, 229]));
        }
    }

    public function testGroupingTracksDeclarationOriginWhenComputedSizeIsUnchanged()
    {
        $view = new HtmlBuilder()->useHtmlRenderer()->build('<style>#box:hover img{max-width:100%}</style><div id="box"><p style="width:5ch">A <a href="/x"><img src="'.self::PNG.'" width="64" height="16" alt="image"></a> Z</p></div>');
        $renderer = new FacadeRenderer(new Renderer());
        $owner = $view->getById('box');
        foreach ([false => 8, true => 1] as $hovered => $expected) {
            $owner->setState('hover', (bool) $hovered);
            $renderer->renderFrame($view->getRoot(), 30, 20);
            $result = $view->getRoot()->getPresentation()->getResult();
            $img = $result->getDocument()->querySelector('img');
            $contents = [];
            $walk = static function ($fragment) use (&$walk, &$contents, $img): void {
                if ($fragment->node === $img && null !== $fragment->content) {
                    $contents[] = $fragment;
                }
                foreach ($fragment->children as $child) {
                    $walk($child);
                }
            };
            $walk($result->getLayout());
            self::assertCount($expected, $contents);
        }
        $owner->setState('hover', false);
        $renderer->renderFrame($view->getRoot(), 30, 20);
        $result = $view->getRoot()->getPresentation()->getResult();
        $img = $result->getDocument()->querySelector('img');
        $contents = [];
        $walk($result->getLayout());
        self::assertCount(8, $contents);
    }

    public function testAuthoredConstraintsAndModesKeepAtomicProvider()
    {
        foreach (['width:8ch', 'height:2lh', 'max-width:4ch', 'max-width:none', 'max-width:100%!important', 'min-width:10ch', 'width:auto;height:auto', 'display:inline-block', 'white-space:nowrap', 'overflow-wrap:normal', 'border:1px solid red', 'padding:1ch', 'opacity:.5'] as $style) {
            $html = '<p style="width:5ch">A <a href="/x"><img src="'.self::PNG.'" width="64" height="16" style="'.$style.'" alt="wide"></a> Z</p>';
            $view = new HtmlBuilder()->useHtmlRenderer()->build($html);
            $renderer = new FacadeRenderer(new Renderer());
            $renderer->renderFrame($view->getRoot(), 30, 20);
            $result = $view->getRoot()->getPresentation()->getResult();
            $img = $result->getDocument()->querySelector('img');
            $contents = [];
            $walk = static function ($f) use (&$walk, &$contents, $img) {if ($f->node === $img && null !== $f->content) {$contents[] = $f; }foreach ($f->children as $c) {$walk($c); }};
            $walk($result->getLayout());
            self::assertCount(1, $contents, $style);
        }
    }

    public function testComputedDefaultsRemainUnchangedWhileVisualCellsAreGrouped()
    {
        $view = new HtmlBuilder()->useHtmlRenderer()->build('<p style="width:5ch">A <a href="/x"><img src="'.self::PNG.'" width="64" height="16" alt="wide"></a> Z</p>');
        $renderer = new FacadeRenderer(new Renderer());
        $renderer->renderFrame($view->getRoot(), 30, 20);
        $result = $view->getRoot()->getPresentation()->getResult();
        $doc = $result->getDocument();
        $img = $doc->querySelector('img');
        $source = $doc->saveHtml();
        self::assertSame('inline-block', $result->getComputedStyle($img)['display']);
        self::assertSame('100%', $result->getComputedStyle($img)['max-width']);
        $contents = [];
        $walk = static function ($f) use (&$walk, &$contents, $img) {
            if ($f->node === $img && null !== $f->content) {
                $contents[] = $f;
            }foreach ($f->children as $c) {
                $walk($c);
            }
        };
        $walk($result->getLayout());
        self::assertCount(8, $contents);
        $renderer->renderFrame($view->getRoot(), 30, 20);
        self::assertSame($img, $doc->querySelector('img'));
        self::assertSame($source, $doc->saveHtml());
    }

    public function testAnimatedDimensionsKeepAtomicContent()
    {
        $view = new HtmlBuilder()->useHtmlRenderer()->build('<style>@keyframes size{from{width:8ch}to{width:4ch}}img{animation:size 1s linear infinite}</style><p style="width:5ch">A <a href="/x"><img src="'.self::PNG.'" width="64" height="16" alt="wide"></a> Z</p>');
        $renderer = new FacadeRenderer(new Renderer());
        $renderer->renderFrame($view->getRoot(), 30, 20);
        $result = $view->getRoot()->getPresentation()->getResult();
        $img = $result->getDocument()->querySelector('img');
        $contents = [];
        $walk = static function ($f) use (&$walk, &$contents, $img) {
            if ($f->node === $img && null !== $f->content) {
                $contents[] = $f;
            }foreach ($f->children as $c) {
                $walk($c);
            }
        };
        $walk($result->getLayout());
        self::assertCount(1, $contents);
    }

    public function testWideAndCombiningSurroundingsKeepNativeCells()
    {
        foreach ([['界 ', ' 後'], ["e\u{0301} ", ' Z']] as [$before,$after]) {
            foreach ([3, 5, 8, 12] as $width) {
                $html = '<p style="width:'.$width.'ch">'.$before.'<a href="https://example.org"><img src="'.self::PNG.'" width="64" height="16" alt="wide"></a>'.$after.'</p>';
                $options = ['columns' => 30, 'rows' => 20];
                $old = FacadeScenario::render($html, $options);
                $new = FacadeScenario::render($html, $options, true);
                self::assertSame(TerminalCellSnapshot::normalize($old, 30, 20, [229, 229, 229]), TerminalCellSnapshot::normalize($new, 30, 20, [229, 229, 229]));
            }
        }
    }

    public function testTransparentAndRightToLeftImagesKeepAtomicContent()
    {
        $image = imagecreatetruecolor(16, 16);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, 0x7F000000);
        imagefilledrectangle($image, 0, 0, 5, 15, 0xFF0000);
        imagefilledrectangle($image, 10, 0, 15, 15, 0x00FF00);
        ob_start();
        imagepng($image);
        $transparent = 'data:image/png;base64,'.base64_encode(ob_get_clean());
        foreach ([['ltr', $transparent], ['rtl', self::PNG]] as [$direction,$asset]) {
            $view = new HtmlBuilder()->useHtmlRenderer()->build('<p style="width:5ch;direction:'.$direction.'">A <a href="/x"><img src="'.$asset.'" width="64" height="16" alt="image"></a> Z</p>');
            $renderer = new FacadeRenderer(new Renderer());
            $renderer->renderFrame($view->getRoot(), 30, 20);
            $result = $view->getRoot()->getPresentation()->getResult();
            $img = $result->getDocument()->querySelector('img');
            $contents = [];
            $walk = static function ($f) use (&$walk, &$contents, $img) {
                if ($f->node === $img && null !== $f->content) {
                    $contents[] = $f;
                }foreach ($f->children as $c) {
                    $walk($c);
                }
            };
            $walk($result->getLayout());
            self::assertCount(1, $contents);
        }
    }

    private function programmaticFrame(bool $core): string
    {
        $builder = new HtmlBuilder();
        if ($core) {
            $builder->useHtmlRenderer();
        }
        $view = $builder->build('<p id="p" style="width:5ch">seed</p>');
        $widget = $view->getById('p');
        $widget->setInlineNodes([\Symfony\Bridge\TuiHtml\Widget\Text\InlineNode::text('A '), \Symfony\Bridge\TuiHtml\Widget\Text\InlineNode::element('img', ['alt' => 'image'], [\Symfony\Bridge\TuiHtml\Widget\Text\InlineNode::text(\Symfony\Bridge\TuiHtml\Widget\RichTextWidget::inlineImageText(0, 8))]), \Symfony\Bridge\TuiHtml\Widget\Text\InlineNode::text(' Z')]);
        $widget->setInlineImages([[new \Symfony\Bridge\TuiHtml\Style\RasterImage(base64_decode(substr(self::PNG, \strlen('data:image/png;base64,')))), 8]]);
        $widget->setLink('https://example.org');
        $terminal = new \Symfony\Component\Tui\Terminal\VirtualTerminal(30, 20);
        $tui = new \Symfony\Bridge\TuiHtml\Tests\Fixtures\HtmlTui(null, $terminal, null, null, $core ? new FacadeRenderer(new Renderer()) : null);
        try {
            $view->mount($tui);
            $tui->start();
            $tui->processRender();
            $screen = new \Symfony\Component\Tui\Terminal\ScreenBuffer(30, 20);
            $screen->write($terminal->getOutput());

            return $screen->getStyledScreen();
        } finally {
            $view->unmount();
            $tui->stop();
        }
    }

    public function testOutlineKeepsTheCompleteAtomicImageDecoration()
    {
        $frames = [];
        foreach (['outline:2px solid red', 'outline:2px solid red;max-width:100%'] as $style) {
            $view = (new HtmlBuilder())->useHtmlRenderer()->build('<p style="width:5ch">A <span><img src="'.self::PNG.'" width="64" height="16" style="'.$style.'"></span> Z</p>');
            $frame = (new FacadeRenderer(new Renderer()))->renderFrame($view->getRoot(), 30, 20);
            $result = $view->getRoot()->getPresentation()->getResult();
            $borders = array_filter($result->getDisplayList()->getOperations(), static fn (array $op): bool => 'border' === $op['type']);
            self::assertCount(1, $borders);
            $frames[] = TerminalCellSnapshot::normalize(implode("\n", $frame->toArray()), 30, 20, [229, 229, 229]);
        }
        self::assertSame($frames[0], $frames[1]);
    }
}
