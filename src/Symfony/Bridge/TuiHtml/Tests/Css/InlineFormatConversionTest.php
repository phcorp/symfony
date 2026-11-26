<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\TuiHtml\Tests\Css;

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\TuiHtml\Css\CssStyleSheet;
use Symfony\Bridge\TuiHtml\Html\HtmlBuilder;
use Symfony\Bridge\TuiHtml\Hyphenation\HyphenatorInterface;
use Symfony\Bridge\TuiHtml\Style\CellSize;
use Symfony\Bridge\TuiHtml\Style\ColorScheme;
use Symfony\Bridge\TuiHtml\Style\Density;
use Symfony\Bridge\TuiHtml\Style\MediaContext;
use Symfony\Bridge\TuiHtml\Widget\RichTextWidget;
use Symfony\Bridge\TuiHtml\Widget\Text\InlineNode;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class InlineFormatConversionTest extends TestCase
{
    public function testRepeatedInlineFormatsKeepTheirIndexesAndCounterEffects()
    {
        $sheet = new CssStyleSheet();
        $view = (new HtmlBuilder($sheet))->build('<style>p{counter-reset:n}span{counter-increment:n}b::before{content:counter(n)}</style><p id="p"><span>a</span><span>b</span><span>c</span><b></b></p>');
        $view->computeStyles();
        $paragraph = $view->getById('p');
        self::assertInstanceOf(RichTextWidget::class, $paragraph);
        $formats = $this->formats($paragraph);

        self::assertSame([0, 1, 2, 3], array_keys($formats));
        self::assertSame('abc3', AnsiUtils::stripAnsiCodes($paragraph->getText()));
        self::assertTrue($formats[0]->equals($formats[1]));
        self::assertTrue($formats[1]->equals($formats[2]));
        self::assertSame('3', $formats[3]->before);
        self::assertNull($formats[0]->before);

        try {
            $formats[0]->letterSpacing = 4;
            self::fail('Inline presentation values must remain readonly.');
        } catch (\Error $error) {
            self::assertStringContainsString('readonly property', $error->getMessage());
        }
        self::assertSame(0, $formats[1]->letterSpacing);
    }

    public function testGeneratedAttributesAndNestedQuotesRemainOwnerSpecific()
    {
        $sheet = new CssStyleSheet();
        $view = (new HtmlBuilder($sheet))->build('<style>span::before{content:attr(data-label)}q{quotes:"[" "]" "(" ")"}</style><p id="p"><span data-label="A">a</span><span data-label="B">b</span><q>c<q>d</q>e</q></p>');
        $view->computeStyles();
        $paragraph = $view->getById('p');
        self::assertInstanceOf(RichTextWidget::class, $paragraph);
        $formats = $this->formats($paragraph);

        self::assertSame('A', $formats[0]->before);
        self::assertSame('B', $formats[1]->before);
        self::assertSame('AaBb[c(d)e]', AnsiUtils::stripAnsiCodes($paragraph->getText()));
        self::assertSame([0, 1, 3, 2], array_keys($formats));
    }

    public function testComputedValuesMediaAndLanguageAreNotReplacedByDeclarationIds()
    {
        $provider = new class implements HyphenatorInterface {
            public int $calls = 0;

            public function hyphenate(string $word, string $language): array
            {
                ++$this->calls;

                return [];
            }
        };
        $sheet = new CssStyleSheet(null, null, false, null, $provider);
        $view = (new HtmlBuilder($sheet))->build('<p id="p"><span style="text-transform:uppercase">ABC</span></p>');
        $view->computeStyles();
        $paragraph = $view->getById('p');
        $reflection = new \ReflectionClass($sheet);
        $parent = $reflection->getProperty('widgetDeclarations')->getValue($sheet)[$paragraph];
        $method = $reflection->getMethod('formatNodes');
        $id = spl_object_id($paragraph);
        $nodes = [InlineNode::element('span', [], [InlineNode::text('ABC')])];
        $lastConversion = null;
        $invoke = static function () use ($method, $sheet, $nodes, $id, $parent, &$lastConversion) {
            $index = 0;
            $formats = [];
            $arguments = [$nodes, $id, $parent, [[], [], [], []], &$index, &$formats, &$lastConversion];
            $method->invokeArgs($sheet, $arguments);

            return $formats[0];
        };
        $reflection->getProperty('languages')->setValue($sheet, [$id.':0' => 'en']);
        $uppercase = $invoke();

        // The declaration IDs stay the same while their computed typography changes.
        $declarations = $reflection->getProperty('declarations')->getValue($sheet);
        foreach ($declarations as &$values) {
            $values['text-transform'] = 'lowercase';
            $values['letter-spacing'] = '8px';
            $values['hyphens'] = 'auto';
        }
        unset($values);
        $reflection->getProperty('declarations')->setValue($sheet, $declarations);
        $lowercase = $invoke();
        $reflection->getProperty('media')->setValue($sheet, new MediaContext(80, 24, ColorScheme::Dark, Density::Regular, false, false, false, new CellSize(8, 32)));
        $larger = $invoke();
        $reflection->getProperty('languages')->setValue($sheet, [$id.':0' => 'fr']);
        $french = $invoke();

        self::assertSame('uppercase', $uppercase->textTransform);
        self::assertSame('lowercase', $lowercase->textTransform);
        self::assertSame(1, $lowercase->letterSpacing);
        self::assertSame(2, $larger->letterSpacing);
        self::assertSame('en', $lowercase->hyphenator->language);
        self::assertSame('fr', $french->hyphenator->language);
        self::assertSame(0, $provider->calls);
    }

    private function formats(RichTextWidget $widget): array
    {
        return new \ReflectionProperty(RichTextWidget::class, 'formats')->getValue($widget);
    }
}
