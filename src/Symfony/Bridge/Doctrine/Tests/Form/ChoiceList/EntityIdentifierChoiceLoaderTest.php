<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Tests\Form\ChoiceList;

use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Form\ChoiceList\EntityIdentifierChoiceLoader;
use Symfony\Bridge\Doctrine\Tests\DoctrineTestHelper;
use Symfony\Bridge\Doctrine\Tests\Fixtures\SingleIntIdEntity;

class EntityIdentifierChoiceLoaderTest extends TestCase
{
    public function testSubmittedValuesArePassedThroughWithoutLoadingTheEntities()
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->never())->method('findAll');
        $loader = $this->createLoader($repository);

        $this->assertSame([0 => 999, 2 => 'abc'], $loader->loadChoicesForValues(['999', '', 'abc']));
        $this->assertSame(['1', ''], $loader->loadValuesForChoices([1, null]));
    }

    private function createLoader(ObjectRepository $repository): EntityIdentifierChoiceLoader
    {
        $em = DoctrineTestHelper::createTestEntityManager();
        $manager = $this->createStub(ObjectManager::class);
        $manager->method('getRepository')->willReturn($repository);
        $manager->method('getClassMetadata')->willReturn($em->getClassMetadata(SingleIntIdEntity::class));

        return new EntityIdentifierChoiceLoader($manager, SingleIntIdEntity::class, 'id');
    }
}
