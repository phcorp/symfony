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
use Symfony\Component\Security\Core\Role\RoleHierarchy;

$count = 0;
$compare = static function (array $hierarchy, array $queries) use (&$count) {
    $expected = null;
    foreach ([BaselineRoleHierarchy::class, GraphRoleHierarchy::class, PrivateRoleHierarchy::class, StringRoleHierarchy::class, ArrayRoleHierarchy::class, RoleHierarchy::class] as $class) {
        $roles = new $class($hierarchy);
        $results = [];
        foreach ($queries as $query) {
            $results[] = [$roles->getReachableRoleNames($query), $roles->getParentRoleNames($query)];
        }
        if (null !== $expected && $results !== $expected) {
            throw new RuntimeException(json_encode([$class, $hierarchy, $queries, $expected, $results]));
        }
        $expected = $results;
    }
    ++$count;
};
foreach ([['ROLE_A', 'ROLE_B', 'ROLE_C'], ['1', '2', '3']] as $nodes) {
    for ($mask = 0; $mask < 512; ++$mask) {
        $hierarchy = array_fill_keys($nodes, []);
        foreach ($nodes as $i => $node) {
            foreach ($nodes as $j => $child) {
                if ($mask & (1 << (3 * $i + $j))) {
                    $hierarchy[$node][] = $child;
                }
            }
        }
        $compare($hierarchy, [[], [$nodes[0]], [$nodes[1]], [$nodes[2]], [$nodes[0], $nodes[2]], [$nodes[0], $nodes[0]], ['missing']]);
    }
}
$random = new Random\Randomizer(new Random\Engine\Mt19937(42));
for ($i = 0; $i < 500; ++$i) {
    $nodes = ['ROLE_A', 'ROLE_B', 'ROLE_C', 'ROLE_D', 'ROLE_E'];
    $hierarchy = [];
    foreach ($nodes as $node) {
        $hierarchy[$node] = [];
        for ($edge = 0; $edge < 4; ++$edge) {
            $hierarchy[$node][] = $nodes[$random->getInt(0, 4)];
        }
    }
    $compare($hierarchy, [[$nodes[0]], [$nodes[3], $nodes[1]], $nodes]);
}
$compare(['ROLE_FOO_*' => ['ROLE_BAR_X'], 'ROLE_BAR_*' => ['ROLE_USER', 'ROLE_FOO_X']], [['ROLE_FOO_A'], ['ROLE_BAR_A'], ['ROLE_FOO_A', 'ROLE_BAR_A']]);
echo "Identical ordered reachable and parent-role results for $count deterministic hierarchies.\n";
