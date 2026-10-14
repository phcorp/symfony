<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/Fixtures/BeforeAfterSorter.php';
require __DIR__.'/Fixtures/PrivateBeforeAfterSorter.php';
require __DIR__.'/Fixtures/GraphBeforeAfterSorter.php';
require __DIR__.'/Fixtures/PreviousTopologicalSort.php';
require __DIR__.'/Fixtures/PreviousBeforeAfterSorter.php';

require __DIR__.'/Fixtures/StringTopologicalSort.php';
require __DIR__.'/Fixtures/StringArrayTopologicalSort.php';
require __DIR__.'/Fixtures/StringBeforeAfterSorter.php';
require __DIR__.'/Fixtures/StringArrayBeforeAfterSorter.php';

use Symfony\Component\DependencyInjection\Compiler\BeforeAfterSorter;
use Symfony\Component\Graph\Benchmarks\Fixtures\BeforeAfterSorter as BaselineSorter;
use Symfony\Component\Graph\Benchmarks\Fixtures\GraphBeforeAfterSorter;
use Symfony\Component\Graph\Benchmarks\Fixtures\PreviousBeforeAfterSorter;
use Symfony\Component\Graph\Benchmarks\Fixtures\PrivateBeforeAfterSorter;

$count = 0;
$compare = static function (string $method, array $arguments) use (&$count) {
    $results = [];
    foreach ([BaselineSorter::class, BeforeAfterSorter::class, PrivateBeforeAfterSorter::class, PreviousBeforeAfterSorter::class, GraphBeforeAfterSorter::class, Symfony\Component\Graph\Benchmarks\Fixtures\StringBeforeAfterSorter::class, Symfony\Component\Graph\Benchmarks\Fixtures\StringArrayBeforeAfterSorter::class] as $class) {
        try {
            $results[] = $class::$method(...$arguments);
        } catch (Throwable $e) {
            $results[] = [$e::class, $e->getMessage()];
        }
    }
    if (array_filter($results, static fn ($result) => $result !== $results[0])) {
        throw new RuntimeException(json_encode([$method, $arguments, $results], \JSON_THROW_ON_ERROR));
    }
    ++$count;
};
foreach ([['a', 'b', 'c'], ['1', '2', '3']] as $nodes) {
    [$a, $b, $c] = $nodes;
    $seeds = [[$a, $b, $c], [$a, $c, $b], [$b, $a, $c], [$b, $c, $a], [$c, $a, $b], [$c, $b, $a]];
    for ($mask = 0; $mask < 512; ++$mask) {
        $constraints = [];
        foreach ($nodes as $i => $node) {
            foreach ($nodes as $j => $dependency) {
                if ($mask & (1 << (3 * $i + $j))) {
                    $constraints[$node]['after'][] = $dependency;
                }
            }
        }
        foreach ($seeds as $seed) {
            $compare('sort', [$seed, $constraints]);
        }
        $compare('sortWithPriorities', [[$a => 5, $b => null, $c => -5], $constraints]);
    }
}
$random = new Random\Randomizer(new Random\Engine\Mt19937(42));
for ($i = 0; $i < 500; ++$i) {
    $seed = ['a', 'b', 'c', 'd', 'e'];
    $constraints = [];
    $priorities = [];
    foreach ($seed as $node) {
        $priorities[$node] = [null, -5, 0, 5][$random->getInt(0, 3)];
        foreach (['within', 'around'] as $direction) {
            for ($edge = 0; $edge < 3; ++$edge) {
                $constraints[$node][$direction][] = ['a', 'b', 'c', 'd', 'e', 'alias', 'missing'][$random->getInt(0, 6)];
            }
        }
    }
    $compare('sort', [$seed, $constraints, ['alias' => ['a', 'b']], 'within', 'around']);
    $compare('sortWithPriorities', [$priorities, $constraints, ['alias' => ['a', 'b']], 'within', 'around']);
}
echo "Identical results and exception types/messages for $count deterministic comparisons.\n";
