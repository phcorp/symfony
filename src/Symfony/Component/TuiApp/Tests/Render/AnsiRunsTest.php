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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\TuiApp\Render\AnsiRuns;
use Symfony\Component\TuiHtml\Style\Style;
use Symfony\Component\TuiHtml\Style\UnderlineStyle;

class AnsiRunsTest extends TestCase
{
    public static function provideLines(): iterable
    {
        yield 'plain' => ['ab', [['t' => 'ab']]];
        yield 'attributes' => ["\e[1;2;3;4;7;9mX\e[0mY", [['t' => 'X', 'b' => true, 'd' => true, 'i' => true, 'r' => true, 's' => true, 'u' => true], ['t' => 'Y']]];
        yield 'basic colors' => ["\e[31;44mX\e[39mY\e[49mZ", [['t' => 'X', 'bg' => '#0000ee', 'fg' => '#cd0000'], ['t' => 'Y', 'bg' => '#0000ee'], ['t' => 'Z']]];
        yield '256 colors' => ["\e[38;5;9mX", [['t' => 'X', 'fg' => '#ff0000']]];
        yield 'RGB colors' => ["\e[38;2;1;2;3;48;2;4;5;6mX", [['t' => 'X', 'bg' => '#040506', 'fg' => '#010203']]];
        yield 'an RGB underline color' => ["\e[1m\e[4m\e[58;2;255;0;0mX", [['t' => 'X', 'b' => true, 'u' => true]]];
        yield 'a 256 underline color' => ["\e[1m\e[58;5;9mX", [['t' => 'X', 'b' => true]]];
        yield 'a styled underline, reset' => ["\e[4:3mX\e[24mY", [['t' => 'X', 'u' => true], ['t' => 'Y']]];
        yield 'a styled underline turned off' => ["\e[1;4mX\e[4:0mY", [['t' => 'X', 'b' => true, 'u' => true], ['t' => 'Y', 'b' => true]]];
        yield 'an RGB color with colons' => ["\e[38:2::255:0:0mX", [['t' => 'X', 'fg' => '#ff0000']]];
        yield 'an RGB color with colons and no color space' => ["\e[48:2:0:255:0mX", [['t' => 'X', 'bg' => '#00ff00']]];
        yield 'a 256 color with colons' => ["\e[38:5:12mX", [['t' => 'X', 'fg' => '#5c5cff']]];
        yield 'the same style again' => ["\e[1mX\e[1mY", [['t' => 'XY', 'b' => true]]];
        yield 'a reset with no parameter' => ["\e[1mX\e[mY", [['t' => 'X', 'b' => true], ['t' => 'Y']]];
        yield 'a hyperlink' => ["\e]8;;https://example.com\e\\\e[1mX\e]8;;\e\\Y", [['t' => 'XY', 'b' => true]]];
    }

    #[DataProvider('provideLines')]
    public function testRuns(string $line, array $runs)
    {
        $this->assertSame($runs, AnsiRuns::parse($line)['runs']);
    }

    public function testACurlyColoredUnderlineFromAStyle()
    {
        $line = (new Style())->withUnderline()->withUnderlineStyle(UnderlineStyle::Curly, '#ff0000')->apply('wavy').'plain';

        $this->assertSame([['t' => 'wavy', 'u' => true], ['t' => 'plain']], AnsiRuns::parse($line)['runs']);
    }

    public function testTheCursor()
    {
        $this->assertSame(['runs' => [['t' => 'ab']], 'cursor' => 1], AnsiRuns::parse('a'.AnsiUtils::cursorMarker().'b'));
    }
}
