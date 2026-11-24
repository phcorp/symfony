<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Input;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Input\KeyParser;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;
use Symfony\Component\TuiApp\Input\KeyEncoder;

class KeyEncoderTest extends TestCase
{
    public static function provideKeyIds(): iterable
    {
        $keys = ['escape', 'tab', 'enter', 'space', 'backspace', 'up', 'down', 'left', 'right', 'home', 'end', 'insert', 'delete', 'page_up', 'page_down', 'f1', 'f2', 'f3', 'f4', 'f5', 'f6', 'f7', 'f8', 'f9', 'f10', 'f11', 'f12', 'a', 'z', '1', '/', '[', '+'];
        $modifiers = ['', 'shift+', 'ctrl+', 'alt+', 'super+', 'ctrl+shift+', 'ctrl+alt+', 'shift+alt+super+'];

        foreach ($keys as $key) {
            foreach ($modifiers as $modifier) {
                yield $modifier.$key => [$modifier.$key];
            }
        }
    }

    #[DataProvider('provideKeyIds')]
    public function testTuiParsesTheEncodedKey(string $keyId)
    {
        $parser = new KeyParser();
        $parser->setKittyProtocolActive(true);

        $this->assertTrue($parser->matches(KeyEncoder::encode($keyId), $keyId), \sprintf('Tui does not read "%s" back from "%s".', $keyId, json_encode(KeyEncoder::encode($keyId))));
    }

    public function testTextIsTypedWhenOnlyShiftIsHeld()
    {
        $this->assertSame('A', KeyEncoder::encode('shift+a', 'A'));
        $this->assertSame('é', KeyEncoder::encode('é', 'é'));
        $this->assertSame("\x1b[97;5u", KeyEncoder::encode('ctrl+a', 'a'));
    }

    public function testANonAsciiKeyIsTypedText()
    {
        $this->assertSame('é', KeyEncoder::encode('ctrl+é', 'é'));
        $this->assertSame('é', KeyEncoder::encode('ctrl+é'));
    }

    public function testControlBytesAreNotTyped()
    {
        $this->assertSame('a', KeyEncoder::encode('a', "\x1b[201~x"));
        $this->assertSame("\x1b[97;5u", KeyEncoder::encode('ctrl+a', "\x01"));
    }

    public function testAControlCharacterIsNotAKey()
    {
        $this->expectException(InvalidArgumentException::class);

        KeyEncoder::encode("\x1b");
    }

    public function testModifiersInAnyOrder()
    {
        $this->assertSame(KeyEncoder::encode('ctrl+shift+s'), KeyEncoder::encode('shift+ctrl+s'));
    }

    public function testUnknownModifier()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown modifier "hold"');

        KeyEncoder::encode('hold+a');
    }

    public function testUnknownKey()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown key "f13"');

        KeyEncoder::encode('f13');
    }

    public function testEmptyTextIsNoText()
    {
        $this->assertSame('a', KeyEncoder::encode('a', ''));
        $this->assertSame(KeyEncoder::encode('ctrl+a'), KeyEncoder::encode('ctrl+a', ''));
        $this->assertSame("\r", KeyEncoder::encode('enter', ''));
    }
}
