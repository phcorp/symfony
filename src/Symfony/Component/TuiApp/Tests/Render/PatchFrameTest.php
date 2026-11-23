<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Render;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Exception\OutOfBoundsException;
use Symfony\Component\TuiApp\Render\PatchFrame;

class PatchFrameTest extends TestCase
{
    public function testAPatchFrameHasNoLines()
    {
        $frame = new PatchFrame([]);

        $this->assertCount(0, $frame);
        $this->assertSame([], $frame->toArray());
        $this->expectException(OutOfBoundsException::class);

        $frame->getLine(0);
    }
}
