<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Tests\Build;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\TuiApp\Build\Builder;
use Symfony\Component\TuiApp\Exception\LogicException;

class BuilderDependenciesTest extends TestCase
{
    public function testTheProcessComponentIsNeededWithAProcessRunner()
    {
        $vendor = array_key_first(array_filter(ClassLoader::getRegisteredLoaders(), static fn (ClassLoader $loader): bool => false !== $loader->findFile(Builder::class)));
        $script = tempnam(sys_get_temp_dir(), 'tui-app-');
        file_put_contents($script, <<<'PHP'
            <?php
            $loader = require $argv[1];
            $loader->unregister();
            spl_autoload_register(static function (string $class) use ($loader): void {
                if (!str_starts_with($class, 'Symfony\Component\Process\\')) {
                    $loader->loadClass($class);
                }
            });
            try {
                new Symfony\Component\TuiApp\Build\Builder(null, static fn (): int => 0);
                echo json_encode(['built']);
            } catch (Throwable $e) {
                echo json_encode([get_class($e), $e->getMessage()]);
            }
            PHP);
        try {
            $process = (new Process([\PHP_BINARY, $script, $vendor.'/autoload.php']))->mustRun();
        } finally {
            unlink($script);
        }

        $this->assertSame([LogicException::class, 'Building the application requires symfony/process. Try running "composer require symfony/process".'], json_decode($process->getOutput(), true), $process->getErrorOutput());
    }
}
