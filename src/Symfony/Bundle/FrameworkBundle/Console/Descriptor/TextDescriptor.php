<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Console\Descriptor;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Dumper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\ErrorHandler\ErrorRenderer\FileLinkFormatter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * @author Jean-François Simon <jeanfrancois.simon@sensiolabs.com>
 *
 * @internal
 */
class TextDescriptor extends Descriptor
{
    public function __construct(
        private ?FileLinkFormatter $fileLinkFormatter = null,
    ) {
    }

    protected function describeRouteCollection(RouteCollection $routes, array $options = []): void
    {
        $showControllers = isset($options['show_controllers']) && $options['show_controllers'];

        $tableHeaders = ['Name', 'Method', 'Scheme', 'Host', 'Path'];
        if ($showControllers) {
            $tableHeaders[] = 'Controller';
        }

        if ($showAliases = $options['show_aliases'] ?? false) {
            $tableHeaders[] = 'Aliases';
        }

        $tableRows = [];
        foreach ($routes->all() as $name => $route) {
            $controller = $route->getDefault('_controller');

            $row = [
                $name,
                $route->getMethods() ? implode('|', $route->getMethods()) : 'ANY',
                $route->getSchemes() ? implode('|', $route->getSchemes()) : 'ANY',
                '' !== $route->getHost() ? $route->getHost() : 'ANY',
                $this->formatControllerLink($controller, $route->getPath(), $options['container'] ?? null),
            ];

            if ($showControllers) {
                $row[] = $controller ? $this->formatControllerLink($controller, $this->formatCallable($controller), $options['container'] ?? null) : '';
            }

            if ($showAliases) {
                $row[] = implode('|', ($reverseAliases ??= $this->getReverseAliases($routes))[$name] ?? []);
            }

            $tableRows[] = $row;
        }

        if (isset($options['output'])) {
            $options['output']->table($tableHeaders, $tableRows);
        } else {
            $table = new Table($this->getOutput());
            $table->setHeaders($tableHeaders)->setRows($tableRows);
            $table->render();
        }
    }

    protected function describeRoute(Route $route, array $options = []): void
    {
        $defaults = $route->getDefaults();
        if (isset($defaults['_controller'])) {
            $defaults['_controller'] = $this->formatControllerLink($defaults['_controller'], $this->formatCallable($defaults['_controller']), $options['container'] ?? null);
        }

        $tableHeaders = ['Property', 'Value'];
        $tableRows = [
            ['Route Name', $options['name'] ?? ''],
            ['Path', $route->getPath()],
            ['Path Regex', $route->compile()->getRegex()],
            ['Host', '' !== $route->getHost() ? $route->getHost() : 'ANY'],
            ['Host Regex', '' !== $route->getHost() ? $route->compile()->getHostRegex() : ''],
            ['Scheme', $route->getSchemes() ? implode('|', $route->getSchemes()) : 'ANY'],
            ['Method', $route->getMethods() ? implode('|', $route->getMethods()) : 'ANY'],
            ['Requirements', $route->getRequirements() ? $this->formatRouterConfig($route->getRequirements()) : 'NO CUSTOM'],
            ['Class', $route::class],
            ['Defaults', $this->formatRouterConfig($defaults)],
            ['Options', $this->formatRouterConfig($route->getOptions())],
        ];

        if ('' !== $route->getCondition()) {
            $tableRows[] = ['Condition', $route->getCondition()];
        }

        $table = new Table($this->getOutput());
        $table->setHeaders($tableHeaders)->setRows($tableRows);
        $table->render();
    }

    protected function describeContainerParameters(ParameterBag $parameters, array $options = []): void
    {
        $tableHeaders = ['Parameter', 'Value'];

        $deprecatedParameters = $parameters->allDeprecated();

        $tableRows = [];
        foreach ($this->sortParameters($parameters) as $parameter => $value) {
            $tableRows[] = [$parameter, $this->formatParameter($value)];

            if (isset($deprecatedParameters[$parameter])) {
                $tableRows[] = [new TableCell(
                    \sprintf('<comment>(Since %s %s: %s)</comment>', $deprecatedParameters[$parameter][0], $deprecatedParameters[$parameter][1], \sprintf(...\array_slice($deprecatedParameters[$parameter], 2))),
                    ['colspan' => 2]
                )];
            }
        }

        $options['output']->title('Symfony Container Parameters');
        $options['output']->table($tableHeaders, $tableRows);
    }

    protected function describeContainerTags(ContainerBuilder $container, array $options = []): void
    {
        $showHidden = isset($options['show_hidden']) && $options['show_hidden'];

        if ($showHidden) {
            $options['output']->title('Symfony Container Hidden Tags');
        } else {
            $options['output']->title('Symfony Container Tags');
        }

        foreach ($this->findDefinitionsByTag($container, $showHidden) as $tag => $definitions) {
            $options['output']->section(\sprintf('"%s" tag', $tag));
            $options['output']->listing(array_keys($definitions));
        }
    }

    protected function describeContainerService(object $service, array $options = [], ?ContainerBuilder $container = null): void
    {
        if (!isset($options['id'])) {
            throw new \InvalidArgumentException('An "id" option must be provided.');
        }

        if ($service instanceof Alias) {
            $this->describeContainerAlias($service, $options, $container);
        } elseif ($service instanceof Definition) {
            $this->describeContainerDefinition($service, $options, $container);
        } else {
            $options['output']->title(\sprintf('Information for Service "<info>%s</info>"', $options['id']));
            $options['output']->table(
                ['Service ID', 'Class'],
                [
                    [$options['id'], $service::class],
                ]
            );
        }
    }

    protected function describeContainerServices(ContainerBuilder $container, array $options = []): void
    {
        $showHidden = isset($options['show_hidden']) && $options['show_hidden'];
        $showTag = $options['tag'] ?? null;

        if ($showHidden) {
            $title = 'Symfony Container Hidden Services';
        } else {
            $title = 'Symfony Container Services';
        }

        if ($showTag) {
            $title .= \sprintf(' Tagged with "%s" Tag', $options['tag']);
        }

        $options['output']->title($title);

        $serviceIds = isset($options['tag']) && $options['tag']
            ? $this->sortTaggedServicesByPriority($container->findTaggedServiceIds($options['tag']))
            : $this->sortServiceIds($container->getServiceIds());
        $maxTags = [];

        if (isset($options['filter'])) {
            $serviceIds = array_filter($serviceIds, $options['filter']);
        }

        foreach ($serviceIds as $key => $serviceId) {
            $definition = $this->resolveServiceDefinition($container, $serviceId);

            // filter out hidden services unless shown explicitly
            if ($showHidden xor '.' === ($serviceId[0] ?? null)) {
                unset($serviceIds[$key]);
                continue;
            }

            if ($definition instanceof Definition) {
                if ($definition->hasTag('container.excluded')) {
                    unset($serviceIds[$key]);
                    continue;
                }
                if ($showTag) {
                    $tags = $definition->getTag($showTag);
                    foreach ($tags as $tag) {
                        foreach ($tag as $key => $value) {
                            if (!isset($maxTags[$key])) {
                                $maxTags[$key] = \strlen($key);
                            }
                            if (\is_array($value)) {
                                $value = $this->formatParameter($value);
                            }

                            if (\strlen($value) > $maxTags[$key]) {
                                $maxTags[$key] = \strlen($value);
                            }
                        }
                    }
                }
            }
        }

        $tagsCount = \count($maxTags);
        $tagsNames = array_keys($maxTags);

        $tableHeaders = array_merge(['Service ID'], $tagsNames, ['Class name']);
        $tableRows = [];
        $rawOutput = isset($options['raw_text']) && $options['raw_text'];
        foreach ($serviceIds as $serviceId) {
            $definition = $this->resolveServiceDefinition($container, $serviceId);

            $styledServiceId = $rawOutput ? $serviceId : \sprintf('<fg=cyan>%s</fg=cyan>', OutputFormatter::escape($serviceId));
            if ($definition instanceof Definition) {
                if ($showTag) {
                    foreach ($this->sortByPriority($definition->getTag($showTag)) as $key => $tag) {
                        $tagValues = [];
                        foreach ($tagsNames as $tagName) {
                            if (\is_array($tagValue = $tag[$tagName] ?? '')) {
                                $tagValue = $this->formatParameter($tagValue);
                            }

                            $tagValues[] = $tagValue;
                        }
                        if (0 === $key) {
                            $tableRows[] = array_merge([$serviceId], $tagValues, [$definition->getClass()]);
                        } else {
                            $tableRows[] = array_merge([' (same service as previous, another tag)'], $tagValues, ['']);
                        }
                    }
                } else {
                    $tableRows[] = [$styledServiceId, $definition->getClass()];
                }
            } elseif ($definition instanceof Alias) {
                $alias = $definition;
                $tableRows[] = array_merge([$styledServiceId, \sprintf('alias for "%s"', $alias)], $tagsCount ? array_fill(0, $tagsCount, '') : []);
            } else {
                $tableRows[] = array_merge([$styledServiceId, $definition::class], $tagsCount ? array_fill(0, $tagsCount, '') : []);
            }
        }

        $options['output']->table($tableHeaders, $tableRows);
    }

    protected function describeContainerDefinition(Definition $definition, array $options = [], ?ContainerBuilder $container = null): void
    {
        if (isset($options['id'])) {
            $options['output']->title(\sprintf('Information for Service "<info>%s</info>"', $options['id']));
        }

        if ('' !== $classDescription = $this->getClassDescription((string) $definition->getClass())) {
            $options['output']->text($classDescription."\n");
        }

        $tableHeaders = ['Option', 'Value'];

        $tableRows[] = ['Service ID', $options['id'] ?? '-'];
        $tableRows[] = ['Class', $definition->getClass() ?: '-'];

        $omitTags = isset($options['omit_tags']) && $options['omit_tags'];
        if (!$omitTags && ($tags = $definition->getTags())) {
            $tagInformation = [];
            foreach ($tags as $tagName => $tagData) {
                foreach ($tagData as $tagParameters) {
                    $parameters = array_map(fn ($key, $value) => \sprintf('<info>%s</info>: %s', $key, \is_array($value) ? $this->formatParameter($value) : $value), array_keys($tagParameters), array_values($tagParameters));
                    $parameters = implode(', ', $parameters);

                    if ('' === $parameters) {
                        $tagInformation[] = \sprintf('%s', $tagName);
                    } else {
                        $tagInformation[] = \sprintf('%s (%s)', $tagName, $parameters);
                    }
                }
            }
            $tagInformation = implode("\n", $tagInformation);
        } else {
            $tagInformation = '-';
        }
        $tableRows[] = ['Tags', $tagInformation];

        $calls = $definition->getMethodCalls();
        if (\count($calls) > 0) {
            $callInformation = [];
            foreach ($calls as $call) {
                $callInformation[] = $call[0];
            }
            $tableRows[] = ['Calls', implode(', ', $callInformation)];
        }

        $tableRows[] = ['Public', $definition->isPublic() && !$definition->isPrivate() ? 'yes' : 'no'];
        $tableRows[] = ['Synthetic', $definition->isSynthetic() ? 'yes' : 'no'];
        $tableRows[] = ['Lazy', $definition->isLazy() ? 'yes' : 'no'];
        $tableRows[] = ['Shared', $definition->isShared() ? 'yes' : 'no'];
        $tableRows[] = ['Abstract', $definition->isAbstract() ? 'yes' : 'no'];
        $tableRows[] = ['Autowired', $definition->isAutowired() ? 'yes' : 'no'];
        $tableRows[] = ['Autoconfigured', $definition->isAutoconfigured() ? 'yes' : 'no'];

        if ($definition->getFile()) {
            $tableRows[] = ['Required File', $definition->getFile()];
        }

        if ($factory = $definition->getFactory()) {
            if (\is_array($factory)) {
                if ($factory[0] instanceof Reference) {
                    $tableRows[] = ['Factory Service', $factory[0]];
                } elseif ($factory[0] instanceof Definition) {
                    $tableRows[] = ['Factory Service', \sprintf('inline factory service (%s)', $factory[0]->getClass() ?? 'class not configured')];
                } else {
                    $tableRows[] = ['Factory Class', $factory[0]];
                }
                $tableRows[] = ['Factory Method', $factory[1]];
            } else {
                $tableRows[] = ['Factory Function', $factory];
            }
        }

        $argumentsInformation = [];
        if ($arguments = $definition->getArguments()) {
            foreach ($arguments as $argument) {
                if ($argument instanceof ServiceClosureArgument) {
                    $argument = $argument->getValues()[0];
                }
                if ($argument instanceof Reference) {
                    $argumentsInformation[] = \sprintf('Service(%s)', (string) $argument);
                } elseif ($argument instanceof IteratorArgument) {
                    if ($argument instanceof TaggedIteratorArgument) {
                        $argumentsInformation[] = \sprintf('Tagged Iterator for "%s"%s', $argument->getTag(), $options['is_debug'] ? '' : \sprintf(' (%d element(s))', \count($argument->getValues())));
                    } else {
                        $argumentsInformation[] = \sprintf('Iterator (%d element(s))', \count($argument->getValues()));
                    }

                    foreach ($argument->getValues() as $ref) {
                        $argumentsInformation[] = \sprintf('- Service(%s)', $ref);
                    }
                } elseif ($argument instanceof ServiceLocatorArgument) {
                    $argumentsInformation[] = \sprintf('Service locator (%d element(s))', \count($argument->getValues()));
                } elseif ($argument instanceof Definition) {
                    $argumentsInformation[] = 'Inlined Service';
                } elseif ($argument instanceof \UnitEnum) {
                    $argumentsInformation[] = ltrim(var_export($argument, true), '\\');
                } elseif ($argument instanceof AbstractArgument) {
                    $argumentsInformation[] = \sprintf('Abstract argument (%s)', $argument->getText());
                } else {
                    $argumentsInformation[] = \is_array($argument) ? \sprintf('Array (%d element(s))', \count($argument)) : $argument;
                }
            }

            $tableRows[] = ['Arguments', implode("\n", $argumentsInformation)];
        }

        $inEdges = null !== $container && isset($options['id']) ? $this->getServiceEdges($container, $options['id']) : [];
        $tableRows[] = ['Usages', $inEdges ? implode(\PHP_EOL, $inEdges) : 'none'];

        $options['output']->table($tableHeaders, $tableRows);
    }

    protected function describeContainerDeprecations(ContainerBuilder $container, array $options = []): void
    {
        $containerDeprecationFilePath = \sprintf('%s/%sDeprecations.log', $container->getParameter('kernel.build_dir'), $container->getParameter('kernel.container_class'));
        if (!file_exists($containerDeprecationFilePath)) {
            $options['output']->warning('The deprecation file does not exist, please try warming the cache first.');

            return;
        }

        $logs = unserialize(file_get_contents($containerDeprecationFilePath));
        if (0 === \count($logs)) {
            $options['output']->success('There are no deprecations in the logs!');

            return;
        }

        $formattedLogs = [];
        $remainingCount = 0;
        foreach ($logs as $log) {
            $formattedLogs[] = \sprintf("%sx: %s\n      in %s:%s", $log['count'], $log['message'], $log['file'], $log['line']);
            $remainingCount += $log['count'];
        }
        $options['output']->title(\sprintf('Remaining deprecations (%s)', $remainingCount));
        $options['output']->listing($formattedLogs);
    }

    protected function describeContainerAlias(Alias $alias, array $options = [], ?ContainerBuilder $container = null): void
    {
        if ($alias->isPublic() && !$alias->isPrivate()) {
            $options['output']->comment(\sprintf('This service is a <info>public</info> alias for the service <info>%s</info>', (string) $alias));
        } else {
            $options['output']->comment(\sprintf('This service is a <comment>private</comment> alias for the service <info>%s</info>', (string) $alias));
        }

        if (!$container) {
            return;
        }

        $this->describeContainerDefinition($container->getDefinition((string) $alias), array_merge($options, ['id' => (string) $alias]), $container);
    }

    protected function describeContainerParameter(mixed $parameter, ?array $deprecation, array $options = []): void
    {
        $parameterName = $options['parameter'];
        $rows = [
            [$parameterName, $this->formatParameter($parameter)],
        ];

        if ($deprecation) {
            $rows[] = [new TableCell(
                \sprintf('<comment>(Since %s %s: %s)</comment>', $deprecation[0], $deprecation[1], \sprintf(...\array_slice($deprecation, 2))),
                ['colspan' => 2]
            )];
        }

        $options['output']->table(['Parameter', 'Value'], $rows);
    }

    protected function describeContainerEnvVars(array $envs, array $options = []): void
    {
        $dump = new Dumper($this->output);
        $options['output']->title('Symfony Container Environment Variables');

        if (null !== $name = $options['name'] ?? null) {
            $options['output']->comment('Displaying detailed environment variable usage matching '.$name);

            $matches = false;
            foreach ($envs as $env) {
                if ($name === $env['name'] || false !== stripos($env['name'], $name)) {
                    $matches = true;
                    $options['output']->section('%env('.$env['processor'].':'.$env['name'].')%');
                    $options['output']->table([], [
                        ['<info>Default value</>', $env['default_available'] ? $dump($env['default_value']) : 'n/a'],
                        ['<info>Real value</>', $env['runtime_available'] ? $dump($env['runtime_value']) : 'n/a'],
                        ['<info>Processed value</>', $env['default_available'] || $env['runtime_available'] ? $dump($env['processed_value']) : 'n/a'],
                    ]);
                }
            }

            if (!$matches) {
                $options['output']->block('None of the environment variables match this name.');
            } else {
                $options['output']->comment('Note real values might be different between web and CLI.');
            }

            return;
        }

        if (!$envs) {
            $options['output']->block('No environment variables are being used.');

            return;
        }

        $rows = [];
        $missing = [];
        foreach ($envs as $env) {
            if (isset($rows[$env['name']])) {
                continue;
            }

            $rows[$env['name']] = [
                $env['name'],
                $env['default_available'] ? $dump($env['default_value']) : 'n/a',
                $env['runtime_available'] ? $dump($env['runtime_value']) : 'n/a',
            ];
            if (!$env['default_available'] && !$env['runtime_available']) {
                $missing[$env['name']] = true;
            }
        }

        $options['output']->table(['Name', 'Default value', 'Real value'], $rows);
        $options['output']->comment('Note real values might be different between web and CLI.');

        if ($missing) {
            $options['output']->warning('The following variables are missing:');
            $options['output']->listing(array_keys($missing));
        }
    }

    protected function describeEventDispatcherListeners(EventDispatcherInterface $eventDispatcher, array $options = []): void
    {
        $event = $options['event'] ?? null;
        $dispatcherServiceName = $options['dispatcher_service_name'] ?? null;

        $title = 'Registered Listeners';

        if (null !== $dispatcherServiceName) {
            $title .= \sprintf(' of Event Dispatcher "%s"', $dispatcherServiceName);
        }

        if (null !== $event) {
            $title .= \sprintf(' for "%s" Event', $event);
            $registeredListeners = $eventDispatcher->getListeners($event);
        } else {
            $title .= ' Grouped by Event';
            // Try to see if "events" exists
            $registeredListeners = \array_key_exists('events', $options) ? array_combine($options['events'], array_map(fn ($event) => $eventDispatcher->getListeners($event), $options['events'])) : $eventDispatcher->getListeners();
        }

        $options['output']->title($title);
        if (null !== $event) {
            $this->renderEventListenerTable($eventDispatcher, $event, $registeredListeners, $options['output']);
        } else {
            ksort($registeredListeners);
            foreach ($registeredListeners as $eventListened => $eventListeners) {
                $options['output']->section(\sprintf('"%s" event', $eventListened));
                $this->renderEventListenerTable($eventDispatcher, $eventListened, $eventListeners, $options['output']);
            }
        }
    }

    protected function describeCallable(mixed $callable, array $options = []): void
    {
        $this->writeText($this->formatCallable($callable), $options);
    }

    private function renderEventListenerTable(EventDispatcherInterface $eventDispatcher, string $event, array $eventListeners, SymfonyStyle $io): void
    {
        $tableHeaders = ['Order', 'Callable', 'Priority'];
        $tableRows = [];

        foreach ($eventListeners as $order => $listener) {
            $tableRows[] = [\sprintf('#%d', $order + 1), $this->formatCallable($listener), $eventDispatcher->getListenerPriority($event, $listener)];
        }

        $io->table($tableHeaders, $tableRows);
    }

    private function formatRouterConfig(array $config): string
    {
        if (!$config) {
            return 'NONE';
        }

        ksort($config);

        $configAsString = '';
        foreach ($config as $key => $value) {
            $configAsString .= \sprintf("\n%s: %s", $key, $this->formatValue($value));
        }

        return trim($configAsString);
    }

    /**
     * @param (callable():ContainerBuilder)|null $getContainer
     */
    private function formatControllerLink(mixed $controller, string $anchorText, ?callable $getContainer = null): string
    {
        if (null === $this->fileLinkFormatter) {
            return $anchorText;
        }

        try {
            if (null === $controller) {
                return $anchorText;
            } elseif (\is_array($controller)) {
                $r = new \ReflectionMethod($controller[0], $controller[1]);
            } elseif ($controller instanceof \Closure) {
                $r = new \ReflectionFunction($controller);
            } elseif (method_exists($controller, '__invoke')) {
                $r = new \ReflectionMethod($controller, '__invoke');
            } elseif (!\is_string($controller)) {
                return $anchorText;
            } elseif (str_contains($controller, '::')) {
                $r = new \ReflectionMethod(...explode('::', $controller, 2));
            } else {
                $r = new \ReflectionFunction($controller);
            }
        } catch (\ReflectionException) {
            if (\is_array($controller)) {
                $controller = implode('::', $controller);
            }

            $id = $controller;
            $method = '__invoke';

            if ($pos = strpos($controller, '::')) {
                $id = substr($controller, 0, $pos);
                $method = substr($controller, $pos + 2);
            }

            if (!$getContainer || !($container = $getContainer()) || !$container->has($id)) {
                return $anchorText;
            }

            try {
                $r = new \ReflectionMethod($container->findDefinition($id)->getClass(), $method);
            } catch (\ReflectionException) {
                return $anchorText;
            }
        }

        $fileLink = $this->fileLinkFormatter->format($r->getFileName(), $r->getStartLine());
        if ($fileLink) {
            return \sprintf('<href=%s>%s</>', $fileLink, $anchorText);
        }

        return $anchorText;
    }

    private function formatCallable(mixed $callable): string
    {
        if (\is_array($callable)) {
            if (\is_object($callable[0])) {
                return \sprintf('%s::%s()', $callable[0]::class, $callable[1]);
            }

            return \sprintf('%s::%s()', $callable[0], $callable[1]);
        }

        if (\is_string($callable)) {
            return \sprintf('%s()', $callable);
        }

        if ($callable instanceof \Closure) {
            $r = new \ReflectionFunction($callable);
            if ($r->isAnonymous()) {
                return 'Closure()';
            }
            if ($class = $r->getClosureCalledClass()) {
                return \sprintf('%s::%s()', $class->name, $r->name);
            }

            return $r->name.'()';
        }

        if (method_exists($callable, '__invoke')) {
            return \sprintf('%s::__invoke()', $callable::class);
        }

        throw new \InvalidArgumentException('Callable is not describable.');
    }

    private function writeText(string $content, array $options = []): void
    {
        $this->write(
            isset($options['raw_text']) && $options['raw_text'] ? strip_tags($content) : $content,
            isset($options['raw_output']) ? !$options['raw_output'] : true
        );
    }
}
