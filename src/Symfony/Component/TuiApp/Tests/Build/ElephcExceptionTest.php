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

use PHPUnit\Framework\TestCase;
use Symfony\Component\TuiApp\Build\ElephcException;

class ElephcExceptionTest extends TestCase
{
    public function testProblemsAreListed()
    {
        $e = ElephcException::fromOutput(file_get_contents(__DIR__.'/../Fixtures/elephc-output.txt'), '/build/app');

        $this->assertCount(40, $e->getProblems());
        $this->assertStringContainsString('reported 40 problems', $e->getMessage());
    }

    public function testOutputWithoutProblems()
    {
        $e = ElephcException::fromOutput("Segmentation fault\n".str_repeat('x', 5000), '/build/app');

        $this->assertSame([], $e->getProblems());
        $this->assertStringStartsWith("elephc failed (the elephc runtime is a beta):\nSegmentation fault", $e->getMessage());
        $this->assertStringNotContainsString('0 problems', $e->getMessage());
        $this->assertLessThan(2500, \strlen($e->getMessage()));
        $this->assertSame("Segmentation fault\n".str_repeat('x', 5000), $e->getOutput());
    }
}
