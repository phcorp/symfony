<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlRenderer\Tests\Css;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlRenderer\Animation\Timeline;
use Symfony\Component\HtmlRenderer\Css\StyleResolver;
use Symfony\Component\HtmlRenderer\Font\FontStyle;
use Symfony\Component\HtmlRenderer\Font\MonospaceTextMeasurer;
use Symfony\Component\HtmlRenderer\Resource\ResourceLoader;

final class PlainValuePreparationTest extends TestCase
{
    public function testRegisteredLiteralValuesStillUseTheirSyntaxAndInitialValues()
    {
        $document = \Dom\HTMLDocument::createFromString('<!doctype html><style>@property --count{syntax:"<number>";inherits:false;initial-value:2}p{--count:invalid}</style><p id="p">Text</p>');
        $source = $document->saveHtml();
        $resolver = new StyleResolver();
        $styles = $resolver->resolve($document, 400, 200);
        $owner = $document->getElementById('p');

        self::assertSame('2', $styles[$owner]['--count']);
        self::assertFalse($resolver->getCascadeRecord($owner)['declarations']['--count']['valid']);
        self::assertSame($source, $document->saveHtml());
    }

    public function testAttributesVariablesAndSiblingFunctionsRemainElementSpecific()
    {
        $document = \Dom\HTMLDocument::createFromString('<!doctype html><style>p{--tone:blue;width:attr(data-width px);font-size:calc(sibling-index()*10px);color:var(--tone)}</style><section><p id="a" data-width="30">A</p><p id="b" data-width="40" style="--tone:red">B</p></section>');
        $resolver = new StyleResolver();
        $a = $document->getElementById('a');
        $b = $document->getElementById('b');
        $styles = $resolver->resolve($document, 400, 200);

        self::assertSame('30px', $styles[$a]['width']);
        self::assertSame('40px', $styles[$b]['width']);
        self::assertSame('10px', $styles[$a]['font-size']);
        self::assertSame('20px', $styles[$b]['font-size']);
        self::assertSame('blue', $styles[$a]['color']);
        self::assertSame('red', $styles[$b]['color']);

        $a->setAttribute('data-width', '50');
        $a->parentElement->insertBefore($b, $a);
        $styles = $resolver->resolve($document, 400, 200);
        self::assertSame('50px', $styles[$a]['width']);
        self::assertSame('20px', $styles[$a]['font-size']);
        self::assertSame('10px', $styles[$b]['font-size']);
    }

    public function testAttributeAndVariableValuesWithoutSiblingFunctionsAreResolved()
    {
        $document = \Dom\HTMLDocument::createFromString('<!doctype html><style>p{--tone:red;width:attr(data-width px);color:var(--tone)}</style><p id="p" data-width="30">Text</p>');
        $resolver = new StyleResolver();
        $owner = $document->getElementById('p');
        $styles = $resolver->resolve($document, 400, 200);

        self::assertSame('30px', $styles[$owner]['width']);
        self::assertSame('red', $styles[$owner]['color']);
        $owner->setAttribute('data-width', '50');
        $styles = $resolver->resolve($document, 400, 200);
        self::assertSame('50px', $styles[$owner]['width']);
    }

    public function testValueCallbacksRemainObservableForEveryOwnerAndFrame()
    {
        $document = \Dom\HTMLDocument::createFromString('<!doctype html><style>p{color:--dynamic()}</style><p>A</p><p>B</p>');
        $calls = 0;
        $color = '#ff0000';
        $callback = static function (string $value, array $scope) use (&$calls, &$color): string {
            ++$calls;

            return $color;
        };
        $resolver = new StyleResolver(new ResourceLoader(), new FontStyle(), new MonospaceTextMeasurer(), [], '', [], null, $callback);
        foreach (['#ff0000', '#0000ff', '#008000'] as $color) {
            $styles = $resolver->resolve($document, 400, 200);
            foreach ($document->querySelectorAll('p') as $owner) {
                self::assertSame($color, $styles[$owner]['color']);
                self::assertSame($color, $resolver->getCascadeRecord($owner)['resolved']['color']);
            }
        }
        self::assertSame(6, $calls);
    }

    public function testPhysicalLogicalAndCssWideValuesKeepTheirCascadeOrder()
    {
        $document = \Dom\HTMLDocument::createFromString('<!doctype html><section style="color:blue;padding:2px"><p id="p" style="writing-mode:vertical-rl;direction:rtl;margin-inline-start:8px;margin-left:2px;padding:inherit;border:1px solid currentColor;color:inherit">Text</p></section>');
        $resolver = new StyleResolver();
        $styles = $resolver->resolve($document, 400, 200);
        $owner = $document->getElementById('p');

        self::assertSame('8px', $styles[$owner]['margin-bottom']);
        self::assertSame('2px', $styles[$owner]['margin-left']);
        self::assertSame('2px', $styles[$owner]['padding-top']);
        self::assertSame('blue', $styles[$owner]['color']);
        self::assertSame('blue', $styles[$owner]['border-left-color']);
        self::assertSame('author', $resolver->getCascadeRecord($owner)['declarations']['margin-left']['origin']);
    }

    public function testStartingStyleAndTransitionsStillPrepareValuesAtEachTime()
    {
        $document = \Dom\HTMLDocument::createFromString('<!doctype html><style>p{color:blue;margin-left:8px;transition:color 1s linear,margin-left 1s linear}@starting-style{p{color:red;margin-left:4px}}</style><p id="p">Text</p>');
        $resolver = new StyleResolver();
        $timeline = new Timeline();
        $owner = $document->getElementById('p');
        $styles = $resolver->resolve($document, 400, 200, [], $timeline, 0.0);
        self::assertSame('red', $styles[$owner]['color']);
        self::assertSame('4px', $styles[$owner]['margin-left']);

        $styles = $resolver->resolve($document, 400, 200, [], $timeline, 0.5);
        self::assertSame('#800080', $styles[$owner]['color']);
        self::assertSame('6px', $styles[$owner]['margin-left']);
        self::assertArrayHasKey('color', $resolver->getCascadeRecord($owner)['animated']);

        $styles = $resolver->resolve($document, 400, 200, [], $timeline, 1.0);
        self::assertSame('blue', $styles[$owner]['color']);
        self::assertSame('8px', $styles[$owner]['margin-left']);
    }
}
