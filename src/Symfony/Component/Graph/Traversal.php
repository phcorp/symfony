<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Graph;

use Symfony\Component\Graph\Internal\NodeIdentity;

final class Traversal
{
    /**
     * Yields each reachable node once, including the roots, in breadth-first order.
     *
     * The roots are consumed before the first yield; adjacency is evaluated after yielding its node.
     *
     * @param iterable<int|string>                       $roots
     * @param callable(int|string): iterable<int|string> $getSuccessors
     *
     * @return \Generator<int, int|string>
     */
    public static function breadthFirst(iterable $roots, callable $getSuccessors): \Generator
    {
        $visited = [];
        $queue = new \SplQueue();

        foreach ($roots as $node) {
            $key = NodeIdentity::key($node);
            if (!isset($visited[$key])) {
                $visited[$key] = true;
                $queue->enqueue($node);
            }
        }

        while (!$queue->isEmpty()) {
            $node = $queue->dequeue();
            yield $node;

            foreach (self::iterate($getSuccessors($node)) as $successor) {
                $key = NodeIdentity::key($successor);
                if (!isset($visited[$key])) {
                    $visited[$key] = true;
                    $queue->enqueue($successor);
                }
            }
        }
    }

    private static function iterate(iterable $nodes): iterable
    {
        return $nodes;
    }
}
