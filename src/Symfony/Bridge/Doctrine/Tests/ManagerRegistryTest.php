<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Tests;

use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Tests\Fixtures\DummyManager;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\VarExporter\LazyObjectInterface;

class ManagerRegistryTest extends TestCase
{
    public function testResetService()
    {
        $container = new ContainerBuilder();

        $container->register('foo', DummyManager::class)->setPublic(true);
        $container->getDefinition('foo')->setLazy(true)->addTag('proxy', ['interface' => ObjectManager::class]);
        $container->compile();

        $dumper = new PhpDumper($container);
        eval('?>'.$dumper->dump(['class' => 'LazyServiceDoctrineBridgeContainer']));

        $container = new \LazyServiceDoctrineBridgeContainer();

        $registry = new TestManagerRegistry('name', [], ['defaultManager' => 'foo'], 'defaultConnection', 'defaultManager', 'proxyInterfaceName');
        $registry->setTestContainer($container);

        $foo = $container->get('foo');
        $foo->bar = 123;
        $this->assertTrue(isset($foo->bar));

        $registry->resetManager();

        $this->assertSame($foo, $container->get('foo'));
        $this->assertInstanceOf(ObjectManager::class, $foo);
        $this->assertFalse(isset($foo->bar));
    }

    /**
     * @requires PHP 8.4
     *
     * @dataProvider provideResetServiceWithNativeLazyObjectsCases
     */
    public function testResetServiceWithNativeLazyObjects(string $class)
    {
        $container = new $class();

        $registry = new TestManagerRegistry(
            'irrelevant',
            [],
            ['defaultManager' => 'foo'],
            'irrelevant',
            'defaultManager',
            'irrelevant',
        );
        $registry->setTestContainer($container);

        $foo = $container->get('foo');
        self::assertSame(DummyManager::class, $foo::class);

        $foo->bar = 123;
        self::assertTrue(isset($foo->bar));

        $registry->resetManager();

        self::assertSame($foo, $container->get('foo'));
        self::assertSame(DummyManager::class, $foo::class);
        self::assertFalse(isset($foo->bar));
    }

    public static function provideResetServiceWithNativeLazyObjectsCases(): iterable
    {
        $container = new ContainerBuilder();

        $container->register('foo', DummyManager::class)->setPublic(true);
        $container->getDefinition('foo')->setLazy(true);
        $container->compile();

        $dumper = new PhpDumper($container);

        eval('?>'.$dumper->dump(['class' => 'NativeLazyServiceDoctrineBridgeContainer']));

        yield ['NativeLazyServiceDoctrineBridgeContainer'];

        $dumps = $dumper->dump(['class' => 'NativeLazyServiceDoctrineBridgeContainerAsFiles', 'as_files' => true]);

        $lastDump = array_pop($dumps);
        foreach (array_reverse($dumps) as $dump) {
            eval('?>'.$dump);
        }
        eval('?>'.$lastDump);

        yield ['NativeLazyServiceDoctrineBridgeContainerAsFiles'];
    }

    /**
     * When performing an entity manager lazy service reset, the reset operations may re-use the container
     * to create a "fresh" service: when doing so, it can happen that the "fresh" service is itself a proxy.
     *
     * Because of that, the proxy will be populated with a wrapped value that is itself a proxy: repeating
     * the reset operation keeps increasing this nesting until the application eventually runs into stack
     * overflow or memory overflow operations, which can happen for long-running processes that rely on
     * services that are reset very often.
     */
    public function testResetServiceWillNotNestFurtherLazyServicesWithinEachOther()
    {
        // This test scenario only applies to containers composed as a set of generated sources
        $this->dumpLazyServiceDoctrineBridgeContainerAsFiles();

        /** @var ContainerInterface $container */
        $container = new \LazyServiceDoctrineBridgeContainerAsFiles();

        $registry = new TestManagerRegistry(
            'irrelevant',
            [],
            ['defaultManager' => 'foo'],
            'irrelevant',
            'defaultManager',
            'irrelevant'
        );
        $registry->setTestContainer($container);

        $service = $container->get('foo');

        self::assertInstanceOf(ObjectManager::class, $service);
        self::assertInstanceOf(LazyObjectInterface::class, $service);
        self::assertFalse($service->isLazyObjectInitialized());

        $service->initializeLazyObject();

        self::assertTrue($container->initialized('foo'));
        self::assertTrue($service->isLazyObjectInitialized());

        $registry->resetManager();
        $service->initializeLazyObject();

        $wrappedValue = $service->initializeLazyObject();
        self::assertInstanceOf(DummyManager::class, $wrappedValue);
        self::assertNotInstanceOf(LazyObjectInterface::class, $wrappedValue);
    }

    private function dumpLazyServiceDoctrineBridgeContainerAsFiles()
    {
        if (class_exists(\LazyServiceDoctrineBridgeContainerAsFiles::class, false)) {
            return;
        }

        $container = new ContainerBuilder();

        $container->register('foo', DummyManager::class)
            ->setPublic(true)
            ->setLazy(true)
            ->addTag('proxy', ['interface' => ObjectManager::class]);
        $container->compile();

        $fileSystem = new Filesystem();

        $temporaryPath = $fileSystem->tempnam(sys_get_temp_dir(), 'symfonyManagerRegistryTest');
        $fileSystem->remove($temporaryPath);
        $fileSystem->mkdir($temporaryPath);

        $dumper = new PhpDumper($container);

        $containerFiles = $dumper->dump([
            'class' => 'LazyServiceDoctrineBridgeContainerAsFiles',
            'as_files' => true,
        ]);

        array_walk(
            $containerFiles,
            static function (string $containerSources, string $fileName) use ($temporaryPath): void {
                (new Filesystem())->dumpFile($temporaryPath.'/'.$fileName, $containerSources);
            }
        );

        require $temporaryPath.'/LazyServiceDoctrineBridgeContainerAsFiles.php';
    }
}
