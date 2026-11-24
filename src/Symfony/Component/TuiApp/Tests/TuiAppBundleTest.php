<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\AbstractKernel;
use Symfony\Component\DependencyInjection\Kernel\KernelTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\TuiApp\Command\BuildCommand;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Mapper\HostEvent;
use Symfony\Component\TuiApp\Mapper\MapContext;
use Symfony\Component\TuiApp\Mapper\Node;
use Symfony\Component\TuiApp\Mapper\WidgetMapperInterface;
use Symfony\Component\TuiApp\TuiAppBundle;

class TuiAppBundleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/sf_tui_app_bundle_test_'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($this->dir.'/composer.json', '{"name": "acme/notes"}');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testConfigurationReachesTheServices()
    {
        $kernel = new TuiAppBundleTestKernel('test', true, $this->dir, ['app' => ['name' => 'Acme Notes', 'version' => '1.2.0'], 'runtime' => ['mode' => 'system']]);
        $kernel->boot();
        $container = $kernel->getContainer();

        $config = $container->get('test.tui_app.config');
        $this->assertInstanceOf(AppConfig::class, $config);
        $this->assertSame('Acme Notes', $config->getName());
        $this->assertSame('acme.notes', $config->getId());
        $this->assertSame(realpath($this->dir), realpath($config->getProjectDir()));
        $this->assertSame('system', $config->toManifest('linux-x86_64')['launch']['mode']);
    }

    public function testTheBuildCommandIsRegistered()
    {
        $kernel = new TuiAppBundleTestKernel('test', true, $this->dir, []);
        $kernel->boot();

        $this->assertInstanceOf(BuildCommand::class, $kernel->getContainer()->get('test.tui_app.command'));
    }

    public function testMappersAreAutoconfigured()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->dir);
        $bundle = new TuiAppBundle();
        $bundle->build($container);
        $bundle->getContainerExtension()->load([[]], $container);
        $container->register(TuiAppBundleTestMapper::class, TuiAppBundleTestMapper::class)->setAutoconfigured(true);
        $container->getDefinition('tui_app.application')->setPublic(true);
        $container->compile();

        $this->assertArrayHasKey(TuiAppBundleTestMapper::class, $container->findTaggedServiceIds('tui_app.widget_mapper'));
    }

    public function testBundledHostsAndVersionFromTheEnvironment()
    {
        $_SERVER['TUI_APP_HOSTS'] = '/opt/hosts';
        $_SERVER['TUI_APP_TEST_VERSION'] = '2.1.0';
        try {
            $kernel = new TuiAppBundleTestKernel('test', true, $this->dir, ['app' => ['version' => '%env(TUI_APP_TEST_VERSION)%']]);
            $kernel->boot();
            $config = $kernel->getContainer()->get('test.tui_app.config');
        } finally {
            unset($_SERVER['TUI_APP_HOSTS'], $_SERVER['TUI_APP_TEST_VERSION']);
        }

        $this->assertSame(\dirname(__DIR__).'/Resources', $config->getHostsDir());
        $this->assertSame('2.1.0', $config->getVersion());
    }

    public function testHostsPathIsNotConfigured()
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->dir);
        (new TuiAppBundle())->getContainerExtension()->load([[]], $container);

        $this->assertArrayNotHasKey('path', $container->getParameter('.tui_app.config')['build']['hosts']);
    }

    public function testInvalidConfiguration()
    {
        $kernel = new TuiAppBundleTestKernel('test', true, $this->dir, ['runtime' => ['mode' => 'elephc'], 'build' => ['targets' => ['windows-x64']]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The elephc runtime builds for');

        $kernel->boot();
    }
}

class TuiAppBundleTestKernel extends AbstractKernel
{
    use KernelTrait;

    public function __construct(string $env, bool $debug, private string $dir, private array $tuiAppConfig)
    {
        parent::__construct($env, $debug);
    }

    public function getProjectDir(): string
    {
        return $this->dir;
    }

    public function registerBundles(): iterable
    {
        yield new TuiAppBundle();
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('tui_app', $this->tuiAppConfig);
        $container->setAlias('test.tui_app.config', 'tui_app.config')->setPublic(true);
        $container->setAlias('test.tui_app.command', 'console.command.tui_app_build')->setPublic(true);
    }
}

class TuiAppBundleTestMapper implements WidgetMapperInterface
{
    public function supports(AbstractWidget $widget): bool
    {
        return false;
    }

    public function map(AbstractWidget $widget, MapContext $context): Node
    {
        return new Node('stack');
    }

    public function handle(AbstractWidget $widget, HostEvent $event): void
    {
    }
}
