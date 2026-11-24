<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Build\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\TuiApp\Build\BuildTarget;
use Symfony\Component\TuiApp\Build\CommandRunner;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Config\ConfigLoader;
use Symfony\Component\TuiApp\Tests\Build\LogRecorder;
use Symfony\Component\TuiApp\Tests\Build\ProcessRunnerStub;

abstract class RuntimeTestCase extends TestCase
{
    protected string $dir;
    protected string $projectDir;
    protected array $log = [];

    protected function setUp(): void
    {
        $this->dir = Path::canonicalize(sys_get_temp_dir()).'/tui-app-runtime-'.bin2hex(random_bytes(4));
        $this->projectDir = $this->dir.'/project';
        $fs = new Filesystem();
        $fs->dumpFile($this->projectDir.'/bin/app.php', '<?php');
        $fs->dumpFile($this->projectDir.'/composer.json', '{"name": "acme/notes"}');
        $fs->dumpFile($this->projectDir.'/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'symfony/tui-html', 'require' => ['ext-dom' => '*']]]]));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    protected function runner(ProcessRunnerStub $stub): CommandRunner
    {
        return new CommandRunner($this->logger(), $stub(...));
    }

    protected function logger(): LogRecorder
    {
        return new LogRecorder(function (string $message): void { $this->log[] = $message; });
    }

    protected function config(array $config): AppConfig
    {
        $config['app']['name'] ??= 'Notes';

        return (new ConfigLoader())->process($config, $this->projectDir);
    }

    /**
     * @param list<string> $prefix
     */
    protected function target(string $name, array $prefix = []): BuildTarget
    {
        $target = new BuildTarget($name, $prefix, $this->dir.'/hosts', $this->dir.'/work', $this->dir.'/out');
        (new Filesystem())->mkdir($target->payloadDir.'/app');

        return $target;
    }
}
