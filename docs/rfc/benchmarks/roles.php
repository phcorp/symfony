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
require __DIR__.'/Fixtures/BaselineRoleHierarchy.php';
require __DIR__.'/Fixtures/GraphRoleHierarchy.php';
require __DIR__.'/Fixtures/PrivateRoleHierarchy.php';
require __DIR__.'/Fixtures/StringTraversal.php';
require __DIR__.'/Fixtures/StringRoleHierarchy.php';
require __DIR__.'/Fixtures/ArrayRoleHierarchy.php';

use Symfony\Component\Graph\Benchmarks\Fixtures\ArrayRoleHierarchy;
use Symfony\Component\Graph\Benchmarks\Fixtures\BaselineRoleHierarchy;
use Symfony\Component\Graph\Benchmarks\Fixtures\GraphRoleHierarchy;
use Symfony\Component\Graph\Benchmarks\Fixtures\PrivateRoleHierarchy;
use Symfony\Component\Graph\Benchmarks\Fixtures\StringRoleHierarchy;

$implementation = $argv[1] ?? 'baseline';
$shape = $argv[2] ?? 'small';
$class = match ($implementation) {
    'baseline' => BaselineRoleHierarchy::class,
    'graph' => GraphRoleHierarchy::class,
    'private' => PrivateRoleHierarchy::class,
    'strings' => StringRoleHierarchy::class,
    'arrays' => ArrayRoleHierarchy::class,
    default => throw new InvalidArgumentException('Unknown implementation.'),
};
$size = match ($shape) {
    'small' => 5,
    'tree' => 127,
    'chain', 'wide' => 300,
    'dense' => 40,
    'cyclic' => 50,
    'duplicates' => 8,
    'layered' => 20,
    default => throw new InvalidArgumentException('Unknown fixture.'),
};
$nodes = array_map(static fn ($i) => 'ROLE_'.$i, range(0, $size - 1));
$hierarchy = [];
foreach ($nodes as $i => $node) {
    $hierarchy[$node] = match ($shape) {
        'wide' => 0 === $i ? array_slice($nodes, 1) : [],
        'dense' => array_slice($nodes, $i + 1),
        'tree' => array_slice($nodes, 2 * $i + 1, 2),
        'duplicates' => isset($nodes[$i + 1]) ? array_fill(0, 3, $nodes[$i + 1]) : [],
        'layered' => array_slice($nodes, 2 * (intdiv($i, 2) + 1), 2),
        default => isset($nodes[$i + 1]) ? [$nodes[$i + 1]] : [],
    };
}
if ('cyclic' === $shape) {
    $hierarchy[$nodes[$size - 1]] = [$nodes[0]];
}
$hash = static function ($roles) use ($nodes) {
    $results = [];
    foreach ($nodes as $node) {
        $results[] = [$roles->getReachableRoleNames([$node]), $roles->getParentRoleNames([$node])];
    }

    return hash('sha256', serialize($results));
};
$expected = $hash(new $class($hierarchy));
$iterations = 'small' === $shape ? 200 : 1;
$samples = $peaks = [];
for ($sample = 0; $sample < 11; ++$sample) {
    gc_collect_cycles();
    memory_reset_peak_usage();
    $memory = memory_get_usage();
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        $roles = new $class($hierarchy);
    }
    $samples[] = (hrtime(true) - $start) / $iterations / 1e6;
    $peaks[] = memory_get_peak_usage() - $memory;
    if ($hash($roles) !== $expected) {
        throw new RuntimeException('Non-deterministic role closure.');
    }
    unset($roles);
}
$counted = match ($implementation) {
    'baseline' => 'Baseline',
    'graph', 'strings' => 'Graph',
    'private' => 'Private',
    'arrays' => 'Array',
};
require __DIR__.'/Fixtures/Counted'.$counted.'RoleHierarchy.php';
$countedClass = 'Symfony\\Component\\Graph\\Benchmarks\\Fixtures\\Counted'.$counted.'RoleHierarchy';
$counts = new $countedClass($hierarchy);
echo json_encode(['implementation' => $implementation, 'shape' => $shape, 'nodes' => $size, 'processed_entries' => $counts->expandedNodes, 'examined_edges' => $counts->examinedEdges, 'edges' => array_sum(array_map('count', $hierarchy)), 'samples_ms' => $samples, 'peak_bytes' => max($peaks), 'result_hash' => $expected, 'php' => \PHP_VERSION, 'deepclone' => phpversion('deepclone'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')], \JSON_PRETTY_PRINT)."\n";
