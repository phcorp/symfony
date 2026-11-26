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
use Symfony\Bridge\TuiHtml\Content\InlineImageCellContent;
use Symfony\Component\HtmlRenderer\Content\ContentLayout;
use Symfony\Component\HtmlRenderer\Paint\DisplayList;

class InlineImageCellContentTest extends TestCase
{
    public function testExistingPortablePaintMetadataSurvivesCellSplitting()
    {
        $operations = [['type' => 'rectangle', 'x' => 0.0, 'y' => 0.0, 'width' => 8.0, 'height' => 16.0, 'color' => 'blue'], ['type' => 'push-opacity', 'opacity' => .5], ['type' => 'text', 'x' => 0.0, 'y' => 0.0, 'width' => 8.0, 'height' => 16.0, 'text' => '▀', 'color' => 'red', 'href' => 'https://example.org', 'link-id' => 'native-id', 'reverse' => true, 'font-size' => 16.0, 'font-weight' => 700, 'font-style' => 'italic', 'text-decoration-line' => 'underline', 'text-decoration-style' => 'double'], ['type' => 'pop-opacity']];
        $cells = InlineImageCellContent::split(new ContentLayout(16, new DisplayList($operations)), 1, 8, 16);
        self::assertCount(1, $cells);
        self::assertSame(8.0, $cells[0]->getIntrinsicSize()->preferredWidth);
        self::assertSame($operations, $cells[0]->layout(8, 16)->displayList->getOperations());
    }

    public function testTransparentAndNonCellPaintUseTheAtomicFallback()
    {
        $text = ['type' => 'text', 'x' => 0.0, 'y' => 0.0, 'width' => 8.0, 'height' => 16.0, 'text' => ' ', 'color' => 'red'];
        self::assertNull(InlineImageCellContent::split(new ContentLayout(16, new DisplayList([$text])), 1, 8, 16));
        $text['text'] = '▀';
        $text['x'] = 4.0;
        self::assertNull(InlineImageCellContent::split(new ContentLayout(16, new DisplayList([$text])), 1, 8, 16));
        $text['x'] = 0.0;
        self::assertNull(InlineImageCellContent::split(new ContentLayout(16, new DisplayList([$text])), 2, 8, 16));
    }
}
