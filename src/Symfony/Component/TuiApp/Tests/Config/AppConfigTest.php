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

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\String\UnicodeString;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Exception\LogicException;
use Symfony\Contracts\Translation\LocaleAwareInterface;

class AppConfigTest extends TestCase
{
    public static function provideMissingClasses(): iterable
    {
        yield 'without the Translation contracts' => [[LocaleAwareInterface::class], 'Café Notes', ['cafe.notes', 'cafe-notes']];
        yield 'without the String component' => [[LocaleAwareInterface::class, UnicodeString::class], 'Acme Notes', ['acme.notes', 'acme-notes']];
        yield 'a name that is not ASCII without the String component' => [[UnicodeString::class], 'Café Notes', [LogicException::class, 'Naming the application from "Café Notes" requires the String component, as it is not ASCII. Try running "composer require symfony/string", or set "app.id".']];
    }

    /**
     * @param list<class-string> $missing
     */
    #[DataProvider('provideMissingClasses')]
    public function testNamesWithoutOptionalPackages(array $missing, string $name, array $expected)
    {
        $vendor = array_key_first(array_filter(ClassLoader::getRegisteredLoaders(), static fn (ClassLoader $loader): bool => false !== $loader->findFile(AppConfig::class)));
        $autoload = $vendor.'/autoload.php';
        $script = tempnam(sys_get_temp_dir(), 'tui-app-');
        file_put_contents($script, <<<'PHP'
            <?php
            $loader = require $argv[1];
            $loader->unregister();
            $missing = json_decode($argv[2], true);
            spl_autoload_register(static function (string $class) use ($loader, $missing): void {
                if (!in_array($class, $missing, true)) {
                    $loader->loadClass($class);
                }
            });
            try {
                $config = (new Symfony\Component\TuiApp\Config\ConfigLoader())->process(['app' => ['name' => $argv[3]]], sys_get_temp_dir().'/tui-app-none');
                echo json_encode([$config->getId(), $config->getExecutableName()]);
            } catch (Throwable $e) {
                echo json_encode([get_class($e), $e->getMessage()]);
            }
            PHP);
        try {
            $process = (new Process([\PHP_BINARY, $script, $autoload, json_encode($missing), $name]))->mustRun();
        } finally {
            unlink($script);
        }

        $this->assertSame($expected, json_decode($process->getOutput(), true), $process->getErrorOutput());
    }
}
