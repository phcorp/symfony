<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\TuiApp\Command\BuildCommand;
use Symfony\Component\TuiApp\Config\ConfigLoader;

class BuildCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/tui-app-command-'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($this->projectDir.'/bin/app.php', '<?php');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testInvalidConfiguration()
    {
        file_put_contents($this->projectDir.'/tui_app.yaml', "app: { id: notes }\n");
        $tester = new CommandTester(new BuildCommand(projectDir: $this->projectDir));

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('is not a reverse-DNS identifier', $tester->getDisplay());
    }

    public function testDumpManifestOfAnUnknownTarget()
    {
        $tester = new CommandTester(new BuildCommand(projectDir: $this->projectDir));

        $this->assertSame(1, $tester->execute(['--dump-manifest' => 'beos-x86']));
        $this->assertStringContainsString('"beos-x86" is not a target', $tester->getDisplay());
    }

    public function testDumpReferenceIsMentionedWithAKernelOnly()
    {
        $this->assertStringNotContainsString('config:dump-reference', (new BuildCommand(projectDir: $this->projectDir))->getHelp());
        $this->assertStringContainsString('config:dump-reference', (new BuildCommand((new ConfigLoader())->process([], $this->projectDir), $this->projectDir))->getHelp());
    }

    public function testConfigFileIsIgnoredWithAKernel()
    {
        $tester = new CommandTester(new BuildCommand((new ConfigLoader())->process([], $this->projectDir), $this->projectDir));

        $tester->execute(['--config' => 'tui_app.yaml', '--dump-manifest' => 'linux-x86_64']);

        $this->assertStringContainsString('"--config" is ignored', $tester->getDisplay());
    }
}
