<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Graph\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Graph\Traversal;

class TraversalTest extends TestCase
{
    public function testEmptyGraph()
    {
        $this->assertSame([], iterator_to_array(Traversal::breadthFirst([], static fn () => []), false));
    }

    public function testBreadthFirstOrderWithCyclesAndDisconnectedRoots()
    {
        $adjacency = ['a' => ['b', 'c', 'b'], 'b' => ['d'], 'c' => ['d', 'a'], 'd' => ['d']];
        $this->assertSame(['a', 'x', 'b', 'c', 'd'], iterator_to_array(Traversal::breadthFirst(['a', 'x', 'a'], static fn ($node) => $adjacency[$node] ?? []), false));
    }

    public function testIdentityAndGeneratorAdjacency()
    {
        $this->assertSame([1, '1'], iterator_to_array(Traversal::breadthFirst([1], static function ($node) {
            if (1 === $node) {
                yield '1';
            }
        }), false));
    }

    public function testTraversalIsLazy()
    {
        $calls = 0;
        foreach (Traversal::breadthFirst(['a'], static function () use (&$calls) {
            ++$calls;

            return ['b'];
        }) as $node) {
            $this->assertSame('a', $node);
            break;
        }
        $this->assertSame(0, $calls);
    }

    public function testLargeGraphSchedulesEachNodeOnce()
    {
        $calls = 0;
        $result = iterator_to_array(Traversal::breadthFirst([0], static function ($node) use (&$calls) {
            ++$calls;

            return $node < 9999 ? [$node + 1, $node + 1] : [];
        }), false);
        $this->assertSame(range(0, 9999), $result);
        $this->assertSame(10000, $calls);
    }

    public function testInvalidAdjacencyIsRejected()
    {
        $this->expectException(\TypeError::class);
        iterator_to_array(Traversal::breadthFirst(['a'], static fn () => null));
    }

    public function testAdjacencyExceptionIsPropagated()
    {
        $exception = new \LogicException('Adjacency failed.');
        try {
            iterator_to_array(Traversal::breadthFirst(['a'], static fn () => throw $exception));
            $this->fail('Expected the callback to fail.');
        } catch (\LogicException $e) {
            $this->assertSame($exception, $e);
        }
    }

    public function testInvalidNodeIsRejected()
    {
        $this->expectException(\TypeError::class);
        iterator_to_array(Traversal::breadthFirst([false], static fn () => []));
    }
}
