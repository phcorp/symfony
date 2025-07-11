<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\Tests\Descriptor;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Descriptor\ApplicationDescription;

final class ApplicationDescriptionTest extends TestCase
{
    /**
     * @dataProvider getNamespacesProvider
     */
    public function testGetNamespaces(array $expected, array $names)
    {
        $application = new TestApplication();
        foreach ($names as $name) {
            $application->addCommand(new Command($name));
        }

        $this->assertSame($expected, array_keys((new ApplicationDescription($application))->getNamespaces()));
    }

    public static function getNamespacesProvider()
    {
        return [
            [['_global'], ['foobar']],
            [['a', 'b'], ['b:foo', 'a:foo', 'b:bar']],
            [['_global', 22, 33, 'b', 'z'], ['z:foo', '1', '33:foo', 'b:foo', '22:foo:bar']],
        ];
    }
}

final class TestApplication extends Application
{
    protected function getDefaultCommands(): array
    {
        return [];
    }
}
