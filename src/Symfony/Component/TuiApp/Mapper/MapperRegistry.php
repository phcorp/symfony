<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Mapper;

use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\TuiApp\Exception\LogicException;

/**
 * Picks the mapper of each widget: the first one that supports it, the
 * mappers given first, then the built-in ones, then the canvas.
 *
 * @experimental
 */
final class MapperRegistry
{
    /** @var list<WidgetMapperInterface> */
    private array $custom;
    /** @var list<WidgetMapperInterface> */
    private array $builtIn;
    /** @var array<class-string, WidgetMapperInterface> */
    private array $byClass = [];

    /**
     * @param iterable<WidgetMapperInterface> $mappers Mappers that win over the built-in ones
     */
    public function __construct(iterable $mappers = [])
    {
        $this->custom = array_values([...$mappers]);
        $this->builtIn = [
            new ContainerMapper(),
            new TextMapper(),
            new InputMapper(),
            new EditorMapper(),
            new SelectListMapper(),
            new SettingsListMapper(),
            new TabsMapper(),
            new CollapsibleMapper(),
            new ProgressBarMapper(),
            new LoaderMapper(),
            new CanvasMapper(),
        ];
    }

    public function get(AbstractWidget $widget): WidgetMapperInterface
    {
        // The application's mappers may look at more than the class of the widget: they are asked every time
        foreach ($this->custom as $mapper) {
            if ($mapper->supports($widget)) {
                return $mapper;
            }
        }

        if (isset($this->byClass[$widget::class])) {
            return $this->byClass[$widget::class];
        }

        foreach ($this->builtIn as $mapper) {
            if ($mapper->supports($widget)) {
                // The built-in mappers decide by class alone
                return $this->byClass[$widget::class] = $mapper;
            }
        }

        throw new LogicException('The canvas mapper supports every widget.');
    }
}
