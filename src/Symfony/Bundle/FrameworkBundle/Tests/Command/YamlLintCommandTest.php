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
use Symfony\Bundle\FrameworkBundle\Command\YamlLintCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Tests the YamlLintCommand.
 *
 * @author Robin Chalas <robin.chalas@gmail.com>
 */
class YamlLintCommandTest extends TestCase
{
    private array $files;

    public function testLintCorrectFile()
    {
        $tester = $this->createCommandTester();
        $filename = $this->createFile('foo: bar');

        $tester->execute(
            ['filename' => $filename],
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE, 'decorated' => false]
        );

        $tester->assertCommandIsSuccessful('Returns 0 in case of success');
        $this->assertStringContainsString('OK', trim($tester->getDisplay()));
    }

    public function testLintIncorrectFile()
    {
        $incorrectContent = '
foo:
bar';
        $tester = $this->createCommandTester();
        $filename = $this->createFile($incorrectContent);

        $tester->execute(['filename' => $filename], ['decorated' => false]);

        $this->assertEquals(1, $tester->getStatusCode(), 'Returns 1 in case of error');
        $this->assertStringContainsString('Unable to parse at line 3 (near "bar").', trim($tester->getDisplay()));
    }

    public function testLintFileNotReadable()
    {
        $tester = $this->createCommandTester();
        $filename = $this->createFile('');
        unlink($filename);

        $this->expectException(\RuntimeException::class);

        $tester->execute(['filename' => $filename], ['decorated' => false]);
    }

    public function testGetHelp()
    {
        $command = new YamlLintCommand();
        $expected = <<<EOF
Or find all files in a bundle:

  <info>php %command.full_name% @AcmeDemoBundle</info>
EOF;

        $this->assertStringContainsString($expected, $command->getHelp());
    }

    public function testLintFilesFromBundleDirectory()
    {
        $tester = $this->createCommandTester($this->getKernelAwareApplicationMock());
        $tester->execute(
            ['filename' => '@AppBundle/Resources'],
            ['verbosity' => OutputInterface::VERBOSITY_VERBOSE, 'decorated' => false]
        );

        $tester->assertCommandIsSuccessful('Returns 0 in case of success');
        $this->assertStringContainsString('[OK] All 0 YAML files contain valid syntax', trim($tester->getDisplay()));
    }

    private function createFile($content): string
    {
        $filename = tempnam(sys_get_temp_dir().'/yml-lint-test', 'sf-');
        file_put_contents($filename, $content);

        $this->files[] = $filename;

        return $filename;
    }

    private function createCommandTester($application = null): CommandTester
    {
        if (!$application) {
            $application = new BaseApplication();
            $command = new YamlLintCommand();
            if (method_exists($application, 'addCommand')) {
                $application->addCommand($command);
            } else {
                $application->add($command);
            }
        }

        $command = $application->find('lint:yaml');

        $command->setApplication($application);

        return new CommandTester($command);
    }

    private function getKernelAwareApplicationMock()
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel
            ->expects($this->once())
            ->method('locateResource')
            ->with('@AppBundle/Resources')
            ->willReturn(sys_get_temp_dir().'/yml-lint-test');

        $application = $this->createMock(Application::class);
        $application
            ->expects($this->once())
            ->method('getKernel')
            ->willReturn($kernel);

        $application
            ->expects($this->once())
            ->method('getHelperSet')
            ->willReturn(new HelperSet());

        $application
            ->expects($this->any())
            ->method('getDefinition')
            ->willReturn(new InputDefinition());

        $application
            ->expects($this->once())
            ->method('find')
            ->with('lint:yaml')
            ->willReturn(new YamlLintCommand());

        return $application;
    }

    protected function setUp(): void
    {
        @mkdir(sys_get_temp_dir().'/yml-lint-test');
        $this->files = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        @rmdir(sys_get_temp_dir().'/yml-lint-test');
    }
}
