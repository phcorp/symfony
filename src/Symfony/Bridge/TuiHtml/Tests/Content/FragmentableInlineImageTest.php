<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\TuiHtml\Tests\Content;

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\TuiHtml\Content\FragmentableInlineImage;
use Symfony\Component\HtmlRenderer\Content\ContentLayout;
use Symfony\Component\HtmlRenderer\Content\IntrinsicSize;
use Symfony\Component\HtmlRenderer\Content\ReplacedContentInterface;
use Symfony\Component\HtmlRenderer\Paint\DisplayList;

class FragmentableInlineImageTest extends TestCase
{
    public function testNaturalRenderingIsLazyAndFragmentsAreCached()
    {
        $whole = new class implements ReplacedContentInterface {
            public int $calls = 0;

            public function getIntrinsicSize(): IntrinsicSize
            {
                return new IntrinsicSize(8, 8, 16);
            }

            public function layout(float $width, float $height): ContentLayout
            {
                ++$this->calls;

                return new ContentLayout(16, new DisplayList([['type' => 'text', 'x' => 0.0, 'y' => 0.0, 'width' => 8.0, 'height' => 16.0, 'text' => '▀', 'color' => 'red']]));
            }
        };
        $lazy = new FragmentableInlineImage($whole, 1, 8, 16);
        self::assertSame(8.0, $lazy->getIntrinsicSize()->preferredWidth);
        self::assertSame(0, $whole->calls);
        $lazy->layout(8, 16);
        self::assertSame(1, $whole->calls);
        $pieces = $lazy->getInlineFragments();
        self::assertCount(1, $pieces);
        self::assertSame(2, $whole->calls);
        self::assertSame($pieces, $lazy->getInlineFragments());
        self::assertSame(2, $whole->calls);
    }

    public function testPublicWholeContentCannotAllocateAnUnboundedPieceList()
    {
        $whole = new class implements ReplacedContentInterface {
            public function getIntrinsicSize(): IntrinsicSize
            {
                return new IntrinsicSize(8, 800008, 16);
            }

            public function layout(float $width, float $height): ContentLayout
            {
                return new ContentLayout(16, new DisplayList([['type' => 'text', 'x' => 0.0, 'y' => 0.0, 'width' => 800008.0, 'height' => 16.0, 'text' => str_repeat('▀', 100001), 'color' => 'red']]));
            }
        };
        $lazy = new FragmentableInlineImage($whole, 100001, 8, 16);
        $this->expectException(\LengthException::class);
        $this->expectExceptionMessage('Inline image fragmentation exceeds the content count limit.');
        $lazy->getInlineFragments();
    }

    public function testTransparentFallbackPreservesActualNativeAdapterIntrinsicAndLayout()
    {
        $widget = new class extends \Symfony\Component\Tui\Widget\AbstractWidget {
            public function render(\Symfony\Component\Tui\Render\RenderContext $context): array
            {
                return [' '];
            }
        };
        $native = new \Symfony\Bridge\TuiHtml\Content\NativeContentAdapter(\WeakReference::create($widget), $widget->getRenderRevision(), [], new \Symfony\Bridge\TuiHtml\Render\TerminalTextMeasurer(), new \Symfony\Component\Tui\Widget\Figlet\FontRegistry(), new \Symfony\Bridge\TuiHtml\Style\MediaContext(), null, 8);
        $lazy = new FragmentableInlineImage($native, 1, 8, 16);
        self::assertSame($native->getIntrinsicSize(), $lazy->getIntrinsicSize());
        self::assertNull($lazy->getInlineFragments());
        self::assertSame($native->getIntrinsicSize(), $lazy->getIntrinsicSize());
        foreach ([8.0, 16.0, 40.0] as $width) {
            self::assertEquals($native->layout($width, 100), $lazy->layout($width, 100));
        }
    }

    public function testPublicLayoutRejectsStaleNativeRevisionBeforeReusingCachedPieces()
    {
        $widget = new class extends \Symfony\Component\Tui\Widget\AbstractWidget {
            public function render(\Symfony\Component\Tui\Render\RenderContext $context): array
            {
                return [str_repeat('▀', 8)];
            }
        };
        $native = new \Symfony\Bridge\TuiHtml\Content\NativeContentAdapter(\WeakReference::create($widget), $widget->getRenderRevision(), [], new \Symfony\Bridge\TuiHtml\Render\TerminalTextMeasurer(), new \Symfony\Component\Tui\Widget\Figlet\FontRegistry(), new \Symfony\Bridge\TuiHtml\Style\MediaContext(), null, 8);
        $lazy = new FragmentableInlineImage($native, 8, 8, 16);
        self::assertCount(8, $lazy->getInlineFragments());
        $widget->invalidate();
        $child = new \Symfony\Component\HtmlRenderer\Layout\Box(null, 'inline-block', ['overflow-wrap' => 'anywhere'], [], null, null, null, null, null, null, null, $lazy);
        $box = new \Symfony\Component\HtmlRenderer\Layout\Box(null, 'block', [], [$child]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Native content changed after preparation');
        (new \Symfony\Component\HtmlRenderer\Layout\LayoutEngine(new \Symfony\Bridge\TuiHtml\Render\TerminalTextMeasurer()))->layout($box, 40, 100);
    }
}
