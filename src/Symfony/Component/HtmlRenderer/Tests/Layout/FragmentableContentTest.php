<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlRenderer\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlRenderer\Content\ContentLayout;
use Symfony\Component\HtmlRenderer\Content\FragmentableContentInterface;
use Symfony\Component\HtmlRenderer\Content\IntrinsicSize;
use Symfony\Component\HtmlRenderer\Content\ReplacedContentInterface;
use Symfony\Component\HtmlRenderer\Layout\Box;
use Symfony\Component\HtmlRenderer\Layout\InlineFormatting;
use Symfony\Component\HtmlRenderer\Paint\DisplayList;

class FragmentableContentTest extends TestCase
{
    public function testOnlyEmergencyRequestsPiecesAndPreservesOwner()
    {
        $provider = $this->provider();
        $owner = \Dom\HTMLDocument::createFromString('<!doctype html><span></span>')->querySelector('span');
        $box = new Box($owner, 'inline-block', ['overflow-wrap' => 'anywhere'], [], null, null, null, '', null, null, null, $provider);
        self::assertSame([$box], InlineFormatting::split($box));
        self::assertSame(0, $provider->calls);
        $budget = 10;
        $pieces = InlineFormatting::split($box, true, $budget);
        self::assertCount(8, $pieces);
        self::assertSame(1, $provider->calls);
        self::assertSame(2, $budget);
        foreach ($pieces as $piece) {
            self::assertSame($owner, $piece->node);
            self::assertNotInstanceOf(FragmentableContentInterface::class, $piece->replacedContent);
        }
    }

    public function testUnsupportedFragmentationKeepsCompleteProvider()
    {
        $provider = $this->provider(true);
        $box = new Box(null, 'inline-block', ['overflow-wrap' => 'anywhere'], [], null, null, null, '', null, null, null, $provider);
        $budget = 10;
        self::assertSame([$box], InlineFormatting::split($box, true, $budget));
        self::assertSame(10, $budget);
    }

    public function testMultipleProvidersShareBudgetBeforeAllocating()
    {
        $box = new Box(null, 'inline-block', ['overflow-wrap' => 'anywhere'], [], null, null, null, '', null, null, null, $this->provider());
        $budget = 12;
        self::assertCount(8, InlineFormatting::split($box, true, $budget));
        $this->expectException(\LengthException::class);
        InlineFormatting::split($box, true, $budget);
    }

    public function testFittingProviderUsesItsCompleteLayoutWithoutFragmentQueries()
    {
        $provider = $this->provider();
        $box = new Box(null, 'inline-block', ['overflow-wrap' => 'anywhere'], [], null, null, null, '', null, null, null, $provider);
        $result = (new \Symfony\Component\HtmlRenderer\Layout\LayoutEngine(new \Symfony\Component\HtmlRenderer\Font\MonospaceTextMeasurer()))->layout($box, 200, 200);
        self::assertSame(0, $provider->calls);
        self::assertSame(1, $provider->layouts);
        self::assertSame(64.0, $result->bounds->width);
        self::assertSame(16.0, $result->bounds->height);
    }

    public function testNestedTextAndProvidersConsumeOneSharedBudget()
    {
        $provider = new Box(null, 'inline-block', ['overflow-wrap' => 'anywhere'], [], null, null, null, '', null, null, null, $this->provider());
        $box = new Box(null, 'inline', ['overflow-wrap' => 'anywhere'], [new Box(null, 'inline', [], [], 'ab'), $provider]);
        $budget = 17;
        $this->expectException(\LengthException::class);
        InlineFormatting::split($box, true, $budget);
    }

    public function testFragmentableSubtypePiecesRemainAtomic()
    {
        $inner = $this->provider();
        $outer = new class($inner) implements FragmentableContentInterface {
            public function __construct(private ReplacedContentInterface $piece)
            {
            }

            public function getIntrinsicSize(): IntrinsicSize
            {
                return new IntrinsicSize(8, 64, 16);
            }

            public function layout(float $width, float $height): ContentLayout
            {
                return new ContentLayout(16, new DisplayList([]));
            }

            public function getInlineFragments(): ?array
            {
                return [$this->piece];
            }
        };
        $box = new Box(null, 'inline-block', ['overflow-wrap' => 'anywhere'], [], null, null, null, '', null, null, null, $outer);
        $budget = 10;
        $piece = InlineFormatting::split($box, true, $budget)[0];
        self::assertNull(InlineFormatting::split($piece, true, $budget));
        self::assertSame(0, $inner->calls);
        self::assertSame(9, $budget);
    }

    public function testInvalidPiecesAreRejectedBeforeConsumingBudget()
    {
        foreach ([[], ['named' => $this->provider()], [new \stdClass()]] as $invalid) {
            $provider = new class($invalid) implements FragmentableContentInterface {
                public function __construct(private array $pieces)
                {
                }

                public function getIntrinsicSize(): IntrinsicSize
                {
                    return new IntrinsicSize(8, 64, 16);
                }

                public function layout(float $width, float $height): ContentLayout
                {
                    return new ContentLayout(16, new DisplayList([]));
                }

                public function getInlineFragments(): ?array
                {
                    return $this->pieces;
                }
            };
            $box = new Box(null, 'inline-block', ['overflow-wrap' => 'anywhere'], [], null, null, null, '', null, null, null, $provider);
            $budget = 10;
            try {
                InlineFormatting::split($box, true, $budget);
                self::fail('Invalid fragment list was accepted.');
            } catch (\UnexpectedValueException) {
                self::assertSame(10, $budget);
            }
        }
    }

    public function testMinContentDoesNotQueryPiecesEvenWhenUnsupported()
    {
        foreach ([false, true] as $unsupported) {
            foreach (['normal' => 64.0, 'break-word' => 64.0, 'anywhere' => 8.0] as $wrap => $expected) {
                $provider = $this->provider($unsupported);
                $child = new Box(null, 'inline-block', ['overflow-wrap' => $wrap], [], null, null, null, '', null, null, null, $provider);
                $box = new Box(null, 'inline-block', ['width' => 'min-content'], [$child]);
                $result = (new \Symfony\Component\HtmlRenderer\Layout\LayoutEngine(new \Symfony\Component\HtmlRenderer\Font\MonospaceTextMeasurer()))->layout($box, 200, 200);
                self::assertSame($expected, $result->bounds->width);
                // The layout may attempt emergency fragmentation only for the narrow anywhere case.
                if ('anywhere' !== $wrap) {
                    self::assertSame(0, $provider->calls);
                }
            }
        }
    }

    public function testFractionalProvidersWrapWithoutCoordinateRescaling()
    {
        $piece = new class implements ReplacedContentInterface {
            public function getIntrinsicSize(): IntrinsicSize
            {
                return new IntrinsicSize(5.5, 5.5, 9.25);
            }

            public function layout(float $width, float $height): ContentLayout
            {
                return new ContentLayout(9.25, new DisplayList([]));
            }
        };
        $provider = new class($piece) implements FragmentableContentInterface {
            public function __construct(private ReplacedContentInterface $piece)
            {
            }

            public function getIntrinsicSize(): IntrinsicSize
            {
                return new IntrinsicSize(5.5, 16.5, 9.25);
            }

            public function layout(float $width, float $height): ContentLayout
            {
                return new ContentLayout(9.25, new DisplayList([]));
            }

            public function getInlineFragments(): ?array
            {
                return array_fill(0, 3, $this->piece);
            }
        };
        $style = ['overflow-wrap' => 'anywhere', 'font-size' => '9.25px', 'line-height' => '9.25px'];
        $box = new Box(null, 'block', $style, [new Box(null, 'inline-block', $style, [], null, null, null, '', null, null, null, $provider)]);
        $result = (new \Symfony\Component\HtmlRenderer\Layout\LayoutEngine(new \Symfony\Component\HtmlRenderer\Font\MonospaceTextMeasurer()))->layout($box, 11, 100);
        $pieces = [];
        $walk = static function ($fragment) use (&$walk, &$pieces) {
            if (null !== $fragment->content) {
                $pieces[] = [$fragment->bounds->x, $fragment->bounds->y, $fragment->bounds->width, $fragment->bounds->height];
            }
            foreach ($fragment->children as $child) {
                $walk($child);
            }
        };
        $walk($result);
        $control = new Box(null, 'block', $style, array_fill(0, 3, new Box(null, 'inline-block', $style, [], null, null, null, '', null, null, null, $piece)));
        $control = (new \Symfony\Component\HtmlRenderer\Layout\LayoutEngine(new \Symfony\Component\HtmlRenderer\Font\MonospaceTextMeasurer()))->layout($control, 11, 100);
        $grouped = $pieces;
        $pieces = [];
        $walk($control);
        self::assertSame($pieces, $grouped);
        self::assertSame([[0.0, 0.0, 5.5, 9.25], [5.5, 0.0, 5.5, 9.25], [0.0, 11.1, 5.5, 9.25]], $grouped);
    }

    public function testOrdinaryAdjacentProvidersKeepTheirExistingMinContentAndBreaks()
    {
        $provider = new \Symfony\Component\HtmlRenderer\Content\AtomicContent($this->provider());
        $child = new Box(null, 'inline-block', ['overflow-wrap' => 'normal'], [], null, null, null, null, null, null, null, $provider);
        $box = new Box(null, 'inline-block', ['width' => 'min-content'], [$child, $child]);
        $result = (new \Symfony\Component\HtmlRenderer\Layout\LayoutEngine(new \Symfony\Component\HtmlRenderer\Font\MonospaceTextMeasurer()))->layout($box, 200, 200);
        self::assertSame(8.0, $result->bounds->width);
        self::assertCount(2, $result->children);
        self::assertSame(64.0, $result->children[0]->bounds->width);
        self::assertSame(64.0, $result->children[1]->bounds->width);
        self::assertGreaterThan($result->children[0]->bounds->y, $result->children[1]->bounds->y);
    }

    public function testOutlinedProviderKeepsOneCompleteDecoration()
    {
        $provider = $this->provider();
        $style = ['overflow-wrap' => 'anywhere', 'outline-style' => 'solid', 'outline-width' => '2px', 'outline-color' => 'red'];
        $child = new Box(null, 'inline-block', $style, [], null, null, null, null, null, null, null, $provider);
        $box = new Box(null, 'block', [], [$child]);
        $fragment = (new \Symfony\Component\HtmlRenderer\Layout\LayoutEngine(new \Symfony\Component\HtmlRenderer\Font\MonospaceTextMeasurer()))->layout($box, 40, 100);
        $operations = (new \Symfony\Component\HtmlRenderer\Paint\Painter())->paint($fragment)->getOperations();
        self::assertCount(1, array_filter($operations, static fn (array $op): bool => 'border' === $op['type']));
        self::assertSame(0, $provider->calls);
    }

    public function testAuthoredAllocatedDimensionsKeepTheCompleteProviderAtomic()
    {
        $counts = [];
        foreach (['width:100px', 'height:32px', 'min-width:100px', 'max-width:20px', 'min-height:32px', 'max-height:8px', 'inline-size:100px', 'block-size:32px', 'min-inline-size:100px', 'max-inline-size:20px', 'min-block-size:32px', 'max-block-size:8px', 'aspect-ratio:2 / 1'] as $css) {
            $provider = $this->provider();
            $actual = $this->publicContentBounds($provider, $css);
            $control = $this->publicContentBounds(new \Symfony\Component\HtmlRenderer\Content\AtomicContent($this->provider()), $css);
            $counts[$css] = \count($actual);
            self::assertSame($control, $actual, $css);
            self::assertSame(0, $provider->calls, $css);
        }
        self::assertSame(array_fill_keys(array_keys($counts), 1), $counts);
    }

    public function testNeutralDimensionDefaultsRetainNaturalFragmentation()
    {
        foreach (['', 'width:auto;height:auto', 'min-width:auto;min-height:auto', 'min-width:0px;min-height:0px', 'max-width:none;max-height:none', 'inline-size:auto;block-size:auto', 'min-inline-size:0px;min-block-size:0px;max-inline-size:none;max-block-size:none', 'aspect-ratio:auto'] as $css) {
            self::assertCount(8, $this->publicContentBounds($this->provider(), $css), $css);
        }
    }

    private function publicContentBounds(ReplacedContentInterface $provider, string $css): array
    {
        $context = new \Symfony\Component\HtmlRenderer\RenderingContext(40, 1000, null, null, null, null, [], '', [], null, static fn (\Dom\Element $node) => 'target' === $node->id ? $provider : null);
        $result = (new \Symfony\Component\HtmlRenderer\HtmlRenderer())->render('<!doctype html><style>html,body,p{margin:0}p{width:5px}span{display:inline-block;overflow-wrap:anywhere}</style><p><span id="target" style="'.$css.'"></span></p>', $context);
        $bounds = [];
        $walk = static function ($fragment) use (&$walk, &$bounds) {
            if (null !== $fragment->content) {
                $bounds[] = [$fragment->bounds->x, $fragment->bounds->y, $fragment->bounds->width, $fragment->bounds->height];
            }
            foreach ($fragment->children as $child) {
                $walk($child);
            }
        };
        $walk($result->getLayout());

        return $bounds;
    }

    private function provider(bool $unsupported = false): FragmentableContentInterface
    {
        return new class($unsupported) implements FragmentableContentInterface {
            public int $calls = 0;
            public int $layouts = 0;

            public function __construct(private bool $unsupported)
            {
            }

            public function getIntrinsicSize(): IntrinsicSize
            {
                return new IntrinsicSize(8, 64, 16);
            }

            public function layout(float $width, float $height): ContentLayout
            {
                ++$this->layouts;

                return new ContentLayout(16, new DisplayList([]));
            }

            public function getInlineFragments(): ?array
            {
                ++$this->calls;
                if ($this->unsupported) {
                    return null;
                }
                $piece = new class implements ReplacedContentInterface {
                    public function getIntrinsicSize(): IntrinsicSize
                    {
                        return new IntrinsicSize(8, 8, 16);
                    }

                    public function layout(float $width, float $height): ContentLayout
                    {
                        return new ContentLayout(16, new DisplayList([]));
                    }
                };

                return array_fill(0, 8, $piece);
            }
        };
    }
}
