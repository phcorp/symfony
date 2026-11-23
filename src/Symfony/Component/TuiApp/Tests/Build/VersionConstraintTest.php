<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Build;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\TuiApp\Build\Builder;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;

class VersionConstraintTest extends TestCase
{
    public static function provideConstraints(): iterable
    {
        yield ['0.27.8', '>=0.27 <0.29', true];
        yield ['0.29.0', '>=0.27 <0.29', false];
        yield ['0.26.0', '>=0.27, <0.29', false];
        yield ['0.28.1', '>=0.27,<0.29', true];
        yield ['0.30.0', '>=0.27 <0.29 || ^0.30', true];
        yield ['1.2.3', 'v1.2.3', true];
    }

    #[DataProvider('provideConstraints')]
    public function testSatisfies(string $version, string $constraint, bool $expected)
    {
        $this->assertSame($expected, Builder::satisfies($version, $constraint));
    }

    public function testAConstraintThatCannotBeRead()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"0.27.*" is not a version constraint');

        Builder::satisfies('0.27.8', '0.27.*');
    }
}
