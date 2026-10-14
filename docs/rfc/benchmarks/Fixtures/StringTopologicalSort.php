<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Graph\Benchmarks\Fixtures;

use Symfony\Component\Graph\Exception\CycleDetectedException;

/**
 * Orders nodes with their dependencies first, preserving depth-first input order.
 */
final class StringTopologicalSort
{
    /**
     * @param iterable<string>                   $nodes           Roots covering the graph to sort
     * @param callable(string): iterable<string> $getDependencies Ordered dependencies of a node
     *
     * @return list<string>
     *
     * @throws CycleDetectedException when a reachable cycle is found
     */
    public static function sort(iterable $nodes, callable $getDependencies): array
    {
        $states = $path = $adjacency = $indexes = $sorted = [];

        foreach ($nodes as $node) {
            $key = self::key($node);
            if (isset($states[$key])) {
                continue;
            }

            $dependencies = $getDependencies($node);
            if ([] === $dependencies) {
                $states[$key] = -1;
                $sorted[] = $node;
                continue;
            }

            $states[$key] = 0;
            $path[] = $node;
            $adjacency[] = self::getAdjacency($dependencies);
            $indexes[] = 0;

            while ($path) {
                $depth = \count($path) - 1;
                $current = $path[$depth];
                $dependencies = $adjacency[$depth];
                $isArray = \is_array($dependencies);

                while ($isArray ? $indexes[$depth] < \count($dependencies) : $dependencies->valid()) {
                    $dependency = $isArray ? $dependencies[$indexes[$depth]++] : $dependencies->current();
                    $key = self::key($dependency);

                    if (isset($states[$key])) {
                        if (0 <= $states[$key]) {
                            $cycle = \array_slice($path, $states[$key]);
                            $cycle[] = $dependency;
                            throw new CycleDetectedException($cycle);
                        }
                        if (!$isArray) {
                            $dependencies->next();
                        }
                        continue;
                    }

                    $nextDependencies = $getDependencies($dependency);
                    if ([] === $nextDependencies) {
                        $states[$key] = -1;
                        $sorted[] = $dependency;
                        if (!$isArray) {
                            $dependencies->next();
                        }
                        continue;
                    }

                    $states[$key] = $depth + 1;
                    $path[] = $dependency;
                    $adjacency[] = self::getAdjacency($nextDependencies);
                    $indexes[] = 0;
                    continue 2;
                }

                array_pop($path);
                array_pop($adjacency);
                array_pop($indexes);
                $states[self::key($current)] = -1;
                $sorted[] = $current;
                if ($path && $adjacency[$depth - 1] instanceof \Generator) {
                    $adjacency[$depth - 1]->next();
                }
            }
        }

        return $sorted;
    }

    private static function getAdjacency(iterable $nodes): array|\Generator
    {
        if (\is_array($nodes) && array_is_list($nodes)) {
            return $nodes;
        }

        return self::getIterator($nodes);
    }

    private static function getIterator(iterable $nodes): \Generator
    {
        yield from $nodes;
    }

    private static function key(mixed $node): string
    {
        if (!\is_string($node)) {
            throw new \TypeError('Graph nodes must be strings.');
        }

        return $node;
    }
}
