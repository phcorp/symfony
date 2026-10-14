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

use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\Graph\Benchmarks\Fixtures\BeforeAfterSorter as BaselineSorter;
use Symfony\Component\Graph\Benchmarks\Fixtures\GraphBeforeAfterSorter;
use Symfony\Component\Graph\Benchmarks\Fixtures\PreviousBeforeAfterSorter;
use Symfony\Component\Graph\Benchmarks\Fixtures\PrivateBeforeAfterSorter;

$implementation = $argv[1] ?? 'baseline';
$shape = $argv[2] ?? 'small';
$class = match ($implementation) {
    'baseline' => BaselineSorter::class,
    'prototype' => GraphBeforeAfterSorter::class,
    'private' => PrivateBeforeAfterSorter::class,
    'strings' => Symfony\Component\Graph\Benchmarks\Fixtures\StringBeforeAfterSorter::class,
    'arrays' => Symfony\Component\Graph\Benchmarks\Fixtures\StringArrayBeforeAfterSorter::class,
    'previous' => PreviousBeforeAfterSorter::class,
    default => throw new \InvalidArgumentException('Unknown implementation.'),
};
$size = match ($shape) {
    'small' => 10,
    'sparse', 'wide', 'isolated', 'duplicates', 'cyclic' => 1000,
    'dense' => 150,
    'deep' => 5000,
    default => throw new \InvalidArgumentException('Unknown fixture.'),
};
$seed = array_map(static fn ($i) => 'n'.$i, range(0, $size - 1));
$constraints = [];
$edges = 0;
foreach ($seed as $i => $node) {
    $targets = match ($shape) {
        'wide' => 0 === $i ? array_slice($seed, 1) : [],
        'isolated' => 0 === $i ? [$seed[1]] : [],
        'dense' => array_slice($seed, $i + 1),
        'duplicates' => isset($seed[$i + 1]) ? array_fill(0, 5, $seed[$i + 1]) : [],
        default => isset($seed[$i + 1]) ? [$seed[$i + 1]] : [],
    };
    $edges += count($targets);
    $constraints[$node] = ['after' => $targets];
}
if ('cyclic' === $shape) {
    $constraints[$seed[$size - 1]] = ['after' => [$seed[0]]];
    ++$edges;
}
$run = static function () use ($class, $seed, $constraints) {
    try {
        return $class::sort($seed, $constraints);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
};
$expected = $run();
$iterations = 'small' === $shape ? 200 : 1;
$samples = [];
$peaks = [];
for ($sample = 0; $sample < 11; ++$sample) {
    gc_collect_cycles();
    memory_reset_peak_usage();
    $memory = memory_get_usage();
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        $result = $run();
    }
    $samples[] = (hrtime(true) - $start) / $iterations / 1e6;
    $peaks[] = memory_get_peak_usage() - $memory;
    if ($result !== $expected) {
        throw new RuntimeException('Non-deterministic result.');
    }
    unset($result);
}
$visitedNodes = $traversedEdges = 0;
try {
    Symfony\Component\Graph\TopologicalSort::sort($seed, static function ($node) use ($constraints, &$visitedNodes, &$traversedEdges) {
        ++$visitedNodes;
        foreach ($constraints[$node]['after'] ?? [] as $dependency) {
            ++$traversedEdges;
            yield $dependency;
        }
    });
} catch (Symfony\Component\Graph\Exception\CycleDetectedException) {
}
echo json_encode(['implementation' => $implementation, 'shape' => $shape, 'nodes' => $size, 'edges' => $edges, 'visited_nodes' => $visitedNodes, 'traversed_edges' => $traversedEdges, 'iterations' => $iterations, 'samples_ms' => $samples, 'peak_bytes' => max($peaks), 'result_hash' => hash('sha256', serialize($expected)), 'php' => \PHP_VERSION, 'deepclone' => phpversion('deepclone'), 'jit' => ini_get('opcache.jit'), 'opcache_cli' => ini_get('opcache.enable_cli')], \JSON_PRETTY_PRINT)."\n";
