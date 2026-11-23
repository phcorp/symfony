<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\MapperRegistry;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;
use Symfony\Component\TuiApp\Protocol\FrameCodec;
use Symfony\Component\TuiApp\Render\NodeTree;

require dirname(__DIR__, 6).'/vendor/autoload.php';

$mapper = new class implements WidgetMapperInterface {
    public bool $full = true;

    public function supports(AbstractWidget $widget): bool
    {
        return true;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        return new Node('stack', [], $this->full ? array_fill(0, 6000, new Node('label', ['text' => 'x'])) : []);
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
    }
};
$root = new ContainerWidget();
$context = new MapContext(new Renderer(), static fn () => 80, 24);
$results = ['decode_20000_frames_ms' => [], 'remove_6000_nodes_ms' => []];
$bytes = str_repeat(FrameCodec::encode(['type' => 'ping']), 20000);
for ($run = 0; $run < 5; ++$run) {
    $codec = new FrameCodec();
    $start = hrtime(true);
    $messages = $codec->decode($bytes);
    $results['decode_20000_frames_ms'][] = (hrtime(true) - $start) / 1e6;
    if (20000 !== count($messages) || $codec->hasPendingBytes()) {
        throw new RuntimeException('The decoder did not consume every frame.');
    }

    $tree = new NodeTree(new MapperRegistry([$mapper]));
    $mapper->full = true;
    $tree->sync($root, $context);
    $mapper->full = false;
    $start = hrtime(true);
    $ops = $tree->sync($root, $context);
    $results['remove_6000_nodes_ms'][] = (hrtime(true) - $start) / 1e6;
    if (6000 !== count($ops) || [] !== $tree->sync($root, $context)) {
        throw new RuntimeException('The tree did not remove every child.');
    }
}
foreach ($results as $name => $samples) {
    sort($samples);
    printf("%s: median %.3f (min %.3f, max %.3f)\n", $name, $samples[2], $samples[0], $samples[4]);
}
