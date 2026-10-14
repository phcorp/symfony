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

use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\BeforeAfterSorter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Graph\Benchmarks\Fixtures\BeforeAfterSorter as BaselineSorter;
use Symfony\Component\Graph\Benchmarks\Fixtures\GraphBeforeAfterSorter;
use Symfony\Component\Graph\Benchmarks\Fixtures\PreviousBeforeAfterSorter;
use Symfony\Component\Graph\Benchmarks\Fixtures\PrivateBeforeAfterSorter;

$implementation = $argv[1] ?? 'prototype';
$shape = $argv[2] ?? 'tags';
if ('baseline' === $implementation) {
    require __DIR__.'/Fixtures/BeforeAfterSorter.php';
    class_alias(BaselineSorter::class, BeforeAfterSorter::class);
} elseif ('private' === $implementation) {
    require __DIR__.'/Fixtures/PrivateBeforeAfterSorter.php';
    class_alias(PrivateBeforeAfterSorter::class, BeforeAfterSorter::class);
} elseif ('previous' === $implementation) {
    require __DIR__.'/Fixtures/PreviousTopologicalSort.php';
    require __DIR__.'/Fixtures/PreviousBeforeAfterSorter.php';
    class_alias(PreviousBeforeAfterSorter::class, BeforeAfterSorter::class);
} elseif ('prototype' === $implementation) {
    require __DIR__.'/Fixtures/GraphBeforeAfterSorter.php';
    class_alias(GraphBeforeAfterSorter::class, BeforeAfterSorter::class);
} else {
    throw new InvalidArgumentException('Unknown implementation.');
}

$build = static function () use ($shape) {
    $container = new ContainerBuilder();
    $container->setParameter('container.build_time', 0);
    $container->register('handlers', IteratorIterator::class)
        ->setPublic(true)->setArguments([new TaggedIteratorArgument('app.handler')]);

    for ($i = 0; $i < 1000; ++$i) {
        $tag = [];
        if ('unconstrained' !== $shape && 0 === $i % 10 && $i < 999) {
            $tag = ['after' => ['handler.'.($i + 1)]];
        }
        $container->register('handler.'.$i, stdClass::class)->addTag('app.handler', $tag);
    }
    if ('decorators' === $shape) {
        for ($group = 0; $group < 20; ++$group) {
            $target = 'base.'.$group;
            $container->register($target, ArrayObject::class)->setPublic(true);
            for ($i = 0; $i < 10; ++$i) {
                $id = $target.'.decorator.'.$i;
                $definition = $container->register($id, ArrayObject::class)
                    ->setArguments([[new Reference($id.'.inner')]])
                    ->setDecoratedService($target);
                if ($i < 9) {
                    $definition->addTag('container.decoration_order', ['within' => [$target.'.decorator.'.($i + 1)]]);
                }
            }
        }
    } elseif ('tags' !== $shape && 'unconstrained' !== $shape) {
        throw new InvalidArgumentException('Unknown fixture.');
    }

    return $container;
};
$compile = static function (ContainerBuilder $container) {
    $container->compile();

    return hash('sha256', (new PhpDumper($container))->dump(['class' => 'GraphBenchmarkContainer']));
};
if ('verify' === ($argv[3] ?? null)) {
    $container = $build();
    if ('decorators' === $shape) {
        $container->getDefinition('base.0.decorator.9')->addTag('container.decoration_order', ['within' => ['base.0.decorator.0']]);
    } elseif ('tags' === $shape) {
        $container->getDefinition('handler.1')->clearTag('app.handler')->addTag('app.handler', ['after' => ['handler.0']]);
    } else {
        throw new InvalidArgumentException('Verification requires a constrained fixture.');
    }
    try {
        $container->compile();
    } catch (Symfony\Component\DependencyInjection\Exception\InvalidArgumentException $e) {
        if (!str_contains($e->getMessage(), 'cycle detected')) {
            throw $e;
        }
        echo $e->getMessage()."\n";
        exit(0);
    }
    throw new RuntimeException('The compilation fixture did not exercise cycle detection.');
}
$expected = $compile($build());
$samples = $peaks = [];
for ($sample = 0; $sample < 11; ++$sample) {
    $container = $build();
    gc_collect_cycles();
    memory_reset_peak_usage();
    $memory = memory_get_usage();
    $start = hrtime(true);
    $container->compile();
    $samples[] = (hrtime(true) - $start) / 1e6;
    $peaks[] = memory_get_peak_usage() - $memory;
    $hash = hash('sha256', (new PhpDumper($container))->dump(['class' => 'GraphBenchmarkContainer']));
    if ($hash !== $expected) {
        throw new RuntimeException('Non-deterministic compiled container.');
    }
    unset($container);
}
echo json_encode(['implementation' => $implementation, 'shape' => $shape, 'samples_ms' => $samples, 'peak_bytes' => max($peaks), 'result_hash' => $expected, 'php' => \PHP_VERSION, 'deepclone' => phpversion('deepclone'), 'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')], \JSON_PRETTY_PRINT)."\n";
