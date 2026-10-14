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
use Symfony\Component\Graph\Internal\NodeIdentity;

/**
 * Orders nodes with their dependencies first, preserving depth-first input order.
 */
final class PreviousTopologicalSort
{
    /**
     * @param iterable<int|string>                       $nodes           Roots covering the graph to sort
     * @param callable(int|string): iterable<int|string> $getDependencies Ordered dependencies of a node
     *
     * @return list<int|string>
     *
     * @throws CycleDetectedException when a reachable cycle is found
     */
    public static function sort(iterable $nodes, callable $getDependencies): array
    {
        $states = $path = $sorted = [];

        foreach ($nodes as $node) {
            $key = NodeIdentity::key($node);
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
            $stack = [[$node, self::iterate($dependencies)]];

            while ($stack) {
                [$current, $dependencies] = $stack[array_key_last($stack)];

                while ($dependencies->valid()) {
                    $dependency = $dependencies->current();
                    $key = NodeIdentity::key($dependency);

                    if (isset($states[$key])) {
                        if (0 <= $states[$key]) {
                            $cycle = \array_slice($path, $states[$key]);
                            $cycle[] = $dependency;
                            throw new CycleDetectedException($cycle);
                        }
                        $dependencies->next();
                        continue;
                    }

                    $nextDependencies = $getDependencies($dependency);
                    if ([] === $nextDependencies) {
                        $states[$key] = -1;
                        $sorted[] = $dependency;
                        $dependencies->next();
                        continue;
                    }

                    $states[$key] = \count($path);
                    $path[] = $dependency;
                    $stack[] = [$dependency, self::iterate($nextDependencies)];
                    continue 2;
                }

                array_pop($stack);
                array_pop($path);
                $states[NodeIdentity::key($current)] = -1;
                $sorted[] = $current;
                if ($stack) {
                    $stack[array_key_last($stack)][1]->next();
                }
            }
        }

        return $sorted;
    }

    private static function iterate(iterable $nodes): \Generator
    {
        yield from $nodes;
    }
}
