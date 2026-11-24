<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\TuiApp\Config\ConfigLoader;
use Symfony\Component\TuiApp\Exception\InvalidArgumentException;

class ConfigLoaderTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/tui-app-loader-'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($this->projectDir.'/bin/app.php', '<?php');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testFrameworkRoot()
    {
        file_put_contents($this->projectDir.'/tui_app.yaml', "framework:\n    tui_app:\n        app: { name: Acme Notes }\n");

        $this->assertSame('Acme Notes', (new ConfigLoader())->load($this->projectDir)->getName());
    }

    public function testEnvAndProjectDirPlaceholders()
    {
        file_put_contents($this->projectDir.'/tui_app.yaml', "app:\n    version: '%env(TUI_APP_TEST_VERSION)%'\n    icon: '%kernel.project_dir%/assets/icon.png'\n");
        $_SERVER['TUI_APP_TEST_VERSION'] = '2.1.0';
        try {
            $config = (new ConfigLoader())->load($this->projectDir)->toArray();
        } finally {
            unset($_SERVER['TUI_APP_TEST_VERSION']);
        }

        $this->assertSame('2.1.0', $config['app']['version']);
        $this->assertSame($this->projectDir.'/assets/icon.png', $config['app']['icon']);
    }

    public function testProjectDirInAFileOfTheConfigDirectory()
    {
        (new Filesystem())->dumpFile($this->projectDir.'/config/tui_app.php', '<?php return ["app" => ["icon" => "%kernel.project_dir%/icon.png"]];');

        $config = (new ConfigLoader())->load($this->projectDir)->toArray();

        $this->assertSame($this->projectDir.'/icon.png', $config['app']['icon']);
    }

    public function testMissingEnvVariable()
    {
        file_put_contents($this->projectDir.'/tui_app.yaml', "app: { version: '%env(TUI_APP_TEST_MISSING)%' }\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The environment variable "TUI_APP_TEST_MISSING" is not set');

        (new ConfigLoader())->load($this->projectDir);
    }

    public function testOtherPlaceholders()
    {
        file_put_contents($this->projectDir.'/tui_app.yaml', "app: { name: '%app.name%' }\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"%app.name%" cannot be resolved without a kernel');

        (new ConfigLoader())->load($this->projectDir);
    }
}
