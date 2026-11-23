<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Terminal;

use PHPUnit\Framework\TestCase;
use Symfony\Component\TuiApp\Terminal\HostTerminal;
use Symfony\Component\TuiApp\Tests\Fixtures\HostStub;

class HostTerminalTest extends TestCase
{
    public function testATitlePoppedGoesBackToTheOnePushed()
    {
        $host = new HostStub();
        $terminal = new HostTerminal($host->connection);
        $terminal->setTitle('Notes');
        $terminal->pushTitle();
        $terminal->setTitle('Editing');
        $host->receive();

        $terminal->popTitle();

        $this->assertSame([['type' => 'window', 'title' => 'Notes']], $host->receive());
    }

    public function testATitlePoppedGoesBackToTheTitleOfTheHost()
    {
        $host = new HostStub();
        $terminal = new HostTerminal($host->connection);
        $terminal->pushTitle();
        $terminal->setTitle('Editing');
        $host->receive();

        $terminal->popTitle();

        $this->assertSame([['type' => 'window', 'title' => null]], $host->receive());
    }

    public function testATitleNotChangedIsNotSentAgain()
    {
        $host = new HostStub();
        $terminal = new HostTerminal($host->connection);
        $terminal->pushTitle();
        $host->receive();

        $terminal->popTitle();

        $this->assertSame([], $host->receive());
    }
}
