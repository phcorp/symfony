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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Graph\Exception\CycleDetectedException;
use Symfony\Component\Graph\TopologicalSort;

class TopologicalSortTest extends TestCase
{
    #[DataProvider('provideGraphs')]
    public function testSort(array $roots, array $adjacency, array $expected)
    {
        $this->assertSame($expected, TopologicalSort::sort($roots, static fn ($node) => $adjacency[$node] ?? []));
    }

    public static function provideGraphs(): iterable
    {
        yield 'empty' => [[], [], []];
        yield 'isolated' => [['a'], [], ['a']];
        yield 'disconnected' => [['a', 'b', 'c'], ['a' => ['x']], ['x', 'a', 'b', 'c']];
        yield 'diamond' => [['a'], ['a' => ['b', 'c'], 'b' => ['d'], 'c' => ['d']], ['d', 'b', 'c', 'a']];
        yield 'duplicates' => [['a', 'a', 'b'], ['a' => ['b', 'b']], ['b', 'a']];
    }

    #[DataProvider('provideCycles')]
    public function testCycle(array $adjacency, array $expected)
    {
        try {
            TopologicalSort::sort(['a'], static fn ($node) => $adjacency[$node] ?? []);
            $this->fail('Expected a cycle.');
        } catch (CycleDetectedException $e) {
            $this->assertSame($expected, $e->getCycle());
        }
    }

    public static function provideCycles(): iterable
    {
        yield 'self' => [['a' => ['a']], ['a', 'a']];
        yield 'simple' => [['a' => ['b'], 'b' => ['a']], ['a', 'b', 'a']];
        yield 'nested' => [['a' => ['b'], 'b' => ['c'], 'c' => ['b', 'a']], ['b', 'c', 'b']];
    }

    public function testIntegerAndStringIdentitiesAreDistinct()
    {
        $this->assertSame(['1', 1], TopologicalSort::sort([1], static fn ($node) => 1 === $node ? ['1'] : []));
        $this->assertSame([0, '0', '', -1], TopologicalSort::sort([0, '0', '', -1], static fn () => []));
    }

    public function testDeepGraphVisitsEachNodeAndEdgeOnce()
    {
        $calls = $edges = 0;
        $result = TopologicalSort::sort([0], static function ($node) use (&$calls, &$edges) {
            ++$calls;
            if ($node < 19999) {
                ++$edges;
                yield $node + 1;
            }
        });
        $this->assertSame(range(19999, 0), $result);
        $this->assertSame(20000, $calls);
        $this->assertSame(19999, $edges);
    }

    public function testIteratorAggregateAndGeneratorRoots()
    {
        $roots = (static function () { yield 'a'; })();
        $this->assertSame(['b', 'a'], TopologicalSort::sort($roots, static fn ($node) => new class($node) implements \IteratorAggregate {
            public function __construct(private string $node)
            {
            }

            public function getIterator(): \Traversable
            {
                if ('a' === $this->node) {
                    yield 'b';
                }
            }
        }));
    }

    public function testArrayAdjacencyKeysAreIgnored()
    {
        $this->assertSame(['b', 'c', 'a'], TopologicalSort::sort(['a'], static fn ($node) => 'a' === $node ? [7 => 'b', 'left' => 'c'] : []));
    }

    public function testMixedArrayAndGeneratorAdjacency()
    {
        $adjacency = [
            'a' => (static function () {
                yield 'b';
                yield 'c';
            })(),
            'b' => ['d'],
            'c' => (static function () {
                yield 'd';
                yield 'e';
            })(),
        ];
        $this->assertSame(['d', 'b', 'e', 'c', 'a'], TopologicalSort::sort(['a'], static fn ($node) => $adjacency[$node] ?? []));
    }

    public function testIteratorAdjacency()
    {
        $this->assertSame(['b', 'c', 'a'], TopologicalSort::sort(new \ArrayIterator(['a']), static fn ($node) => new \ArrayIterator('a' === $node ? ['x' => 'b', 'y' => 'c'] : [])));
    }

    public function testAdjacencyIsResumedAfterVisitingTheDependency()
    {
        $visited = [];
        $exception = new \LogicException('Iteration failed.');
        try {
            TopologicalSort::sort(['a'], static function ($node) use (&$visited, $exception) {
                $visited[] = $node;
                if ('a' === $node) {
                    yield 'b';
                    throw $exception;
                }
            });
            $this->fail('Expected the iterator to fail.');
        } catch (\LogicException $e) {
            $this->assertSame($exception, $e);
            $this->assertSame(['a', 'b'], $visited);
        }
    }

    public function testDenseGraphVisitsEachNodeOnce()
    {
        $calls = 0;
        $this->assertSame(range(99, 0), TopologicalSort::sort([0], static function ($node) use (&$calls) {
            ++$calls;

            return $node < 99 ? range($node + 1, 99) : [];
        }));
        $this->assertSame(100, $calls);
    }

    public function testAdjacencyExceptionIsPropagated()
    {
        $exception = new \LogicException('Adjacency failed.');
        try {
            TopologicalSort::sort(['a'], static fn () => throw $exception);
            $this->fail('Expected the callback to fail.');
        } catch (\LogicException $e) {
            $this->assertSame($exception, $e);
        }
    }

    #[DataProvider('provideInvalidNodes')]
    public function testInvalidNodeIsRejected(mixed $node)
    {
        $this->expectException(\TypeError::class);
        TopologicalSort::sort(['a'], static fn () => [$node]);
    }

    public static function provideInvalidNodes(): iterable
    {
        yield [new \stdClass()];
        yield [false];
        yield [1.5];
        yield [null];
        yield [[]];
    }

    public function testInvalidAdjacencyIsRejected()
    {
        $this->expectException(\TypeError::class);
        TopologicalSort::sort(['a'], static fn () => null);
    }
}
