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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlRenderer\Font\MonospaceTextMeasurer;
use Symfony\Component\HtmlRenderer\Layout\Box;
use Symfony\Component\HtmlRenderer\Layout\LayoutEngine;
use Symfony\Component\HtmlRenderer\Layout\TableGeometry;

final class TableGroupOrderTest extends TestCase
{
    #[DataProvider('provideOrders')]
    public function testGroupOrderPreservesSourceSlots(array $roles, array $expected)
    {
        self::assertSame($expected, TableGeometry::groupOrder($roles) ?? array_keys($roles));
    }

    public static function provideOrders(): iterable
    {
        yield 'captions and nonrows keep their slots' => [
            ['before' => null, 'foot' => 'footer', 'middle' => null, 'body' => 'row', 'head' => 'header', 'after' => null],
            ['before', 'head', 'middle', 'body', 'foot', 'after'],
        ];
        yield 'only first header and footer move' => [
            ['f1' => 'footer', 'h1' => 'header', 'body' => 'row', 'h2' => 'header', 'f2' => 'footer'],
            ['h1', 'body', 'h2', 'f2', 'f1'],
        ];
        yield 'a later dual classification supplies the footer' => [
            ['a' => 'both', 'b' => 'both', 'body' => 'row'],
            ['a', 'body', 'b'],
        ];
        yield 'integer and string source keys' => [
            [-8 => null, 'footer' => 'footer', 21 => 'row', 'header' => 'header', 55 => null],
            [-8, 'header', 21, 'footer', 55],
        ];
        yield 'ordinary rows' => [[0 => 'row', 1 => 'row'], [0, 1]];
        yield 'no rows' => [['caption' => null], ['caption']];
        yield 'empty' => [[], []];
    }

    #[DataProvider('provideGeometry')]
    public function testGroupRolesDetermineActualRowGeometry(array $roles, array $expected)
    {
        $document = \Dom\HTMLDocument::createFromString('<!doctype html><main></main>');
        $nodes = $groups = [];
        foreach ($roles as $label => $role) {
            $cell = $document->createElement('div');
            $nodes[$label] = $cell;
            $text = new Box(null, 'inline', [], [], $label);
            $row = new Box(null, 'table-row', [], [new Box($cell, 'table-cell', [], [$text])]);
            $display = match ($role) {
                'header', 'both', 'empty-header' => 'table-header-group',
                'footer' => 'table-footer-group',
                default => 'table-row-group',
            };
            $native = $document->createElement('both' === $role ? 'tfoot' : 'div');
            $groups[] = new Box($native, $display, [], 'empty-header' === $role ? [] : [$row]);
        }
        $table = new Box(null, 'table', ['font-size' => '10px', 'line-height' => '10px'], $groups);
        $fragment = new LayoutEngine(new MonospaceTextMeasurer())->layout($table, 100, 100);
        $positions = [];
        $walk = static function ($fragment) use (&$walk, &$positions, $nodes): void {
            foreach ($nodes as $label => $node) {
                if ($fragment->node === $node) {
                    $positions[$label] = $fragment->bounds->y;
                }
            }
            foreach ($fragment->children as $child) {
                $walk($child);
            }
        };
        $walk($fragment);
        asort($positions);
        self::assertSame($expected, $positions);
    }

    public static function provideGeometry(): iterable
    {
        yield 'duplicates' => [
            ['f1' => 'footer', 'h1' => 'header', 'body' => 'row', 'h2' => 'header', 'f2' => 'footer'],
            ['h1' => 0.0, 'body' => 10.0, 'h2' => 20.0, 'f2' => 30.0, 'f1' => 40.0],
        ];
        yield 'native and display both identify groups' => [
            ['a' => 'both', 'b' => 'both', 'body' => 'row'],
            ['a' => 0.0, 'body' => 10.0, 'b' => 20.0],
        ];
        yield 'empty first header still owns header role' => [
            ['footer' => 'footer', 'empty' => 'empty-header', 'body' => 'row', 'later' => 'header'],
            ['body' => 0.0, 'later' => 10.0, 'footer' => 20.0],
        ];
        yield 'ordinary row group' => [['body' => 'row'], ['body' => 0.0]];
    }
}
