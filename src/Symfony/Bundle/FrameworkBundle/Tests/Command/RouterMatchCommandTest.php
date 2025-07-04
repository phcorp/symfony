<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Command\RouterDebugCommand;
use Symfony\Bundle\FrameworkBundle\Command\RouterMatchCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

class RouterMatchCommandTest extends TestCase
{
    public function testWithMatchPath()
    {
        $tester = $this->createCommandTester();
        $ret = $tester->execute(['path_info' => '/foo', 'foo'], ['decorated' => false]);

        $this->assertEquals(0, $ret, 'Returns 0 in case of success');
        $this->assertStringContainsString('Route Name   | foo', $tester->getDisplay());
    }

    public function testWithNotMatchPath()
    {
        $tester = $this->createCommandTester();
        $ret = $tester->execute(['path_info' => '/test', 'foo'], ['decorated' => false]);

        $this->assertEquals(1, $ret, 'Returns 1 in case of failure');
        $this->assertStringContainsString('None of the routes match the path "/test"', $tester->getDisplay());
    }

    private function createCommandTester(): CommandTester
    {
        $application = new Application($this->getKernel());
        $application->addCommand(new RouterMatchCommand($this->getRouter()));
        $application->addCommand(new RouterDebugCommand($this->getRouter()));

        return new CommandTester($application->find('router:match'));
    }

    private function getRouter()
    {
        $routeCollection = new RouteCollection();
        $routeCollection->add('foo', new Route('foo'));
        $requestContext = new RequestContext();
        $router = $this->createMock(RouterInterface::class);
        $router
            ->expects($this->any())
            ->method('getRouteCollection')
            ->willReturn($routeCollection);
        $router
            ->expects($this->any())
            ->method('getContext')
            ->willReturn($requestContext);

        return $router;
    }

    private function getKernel()
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel
            ->expects($this->any())
            ->method('getContainer')
            ->willReturn(new Container())
        ;
        $kernel
            ->expects($this->once())
            ->method('getBundles')
            ->willReturn([])
        ;

        return $kernel;
    }
}
