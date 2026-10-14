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

final class StringArrayTopologicalSort
{
    /**
     * @param list<string>                $nodes
     * @param array<string, list<string>> $adjacency Prevalidated adjacency lists; missing nodes have no dependencies
     *
     * @return list<string>
     */
    public static function sort(array $nodes, array $adjacency): array
    {
        $states = $path = $indexes = $sorted = [];
        foreach ($nodes as $node) {
            $node = self::key($node);
            if (isset($states[$node])) {
                continue;
            }
            $states[$node] = 0;
            $path[] = $node;
            $indexes[] = 0;
            while ($path) {
                $depth = \count($path) - 1;
                $current = $path[$depth];
                $dependencies = $adjacency[$current] ?? [];
                for ($i = $indexes[$depth]; $i < \count($dependencies); ++$i) {
                    $dependency = self::key($dependencies[$i]);
                    if (isset($states[$dependency])) {
                        if (0 <= $states[$dependency]) {
                            $cycle = \array_slice($path, $states[$dependency]);
                            $cycle[] = $dependency;
                            throw new CycleDetectedException($cycle);
                        }
                        continue;
                    }
                    $indexes[$depth] = $i + 1;
                    $states[$dependency] = $depth + 1;
                    $path[] = $dependency;
                    $indexes[] = 0;
                    continue 2;
                }
                array_pop($path);
                array_pop($indexes);
                $states[$current] = -1;
                $sorted[] = $current;
            }
        }

        return $sorted;
    }

    private static function key(mixed $node): string
    {
        if (!\is_string($node)) {
            throw new \TypeError('Graph nodes must be strings.');
        }

        return $node;
    }
}
