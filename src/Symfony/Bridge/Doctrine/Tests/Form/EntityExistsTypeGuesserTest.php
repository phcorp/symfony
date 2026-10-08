<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Tests\Form;

use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Form\EntityExistsTypeGuesser;
use Symfony\Bridge\Doctrine\Form\Type\EntityIdentifierType;
use Symfony\Bridge\Doctrine\Tests\DoctrineTestHelper;
use Symfony\Bridge\Doctrine\Tests\Fixtures\EntityExistsDto;
use Symfony\Bridge\Doctrine\Tests\Fixtures\SingleIntIdEntity;
use Symfony\Bridge\Doctrine\Tests\Fixtures\SingleStringIdEntity;
use Symfony\Bridge\Doctrine\Tests\Fixtures\UuidIdEntity;
use Symfony\Component\Form\Guess\Guess;
use Symfony\Component\Form\Guess\ValueGuess;
use Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory;
use Symfony\Component\Validator\Mapping\Loader\AttributeLoader;

class EntityExistsTypeGuesserTest extends TestCase
{
    private EntityManager $em;
    private EntityExistsTypeGuesser $guesser;

    protected function setUp(): void
    {
        $this->em = DoctrineTestHelper::createTestEntityManager();
        $this->guesser = new EntityExistsTypeGuesser($this->createRegistry(), new LazyLoadingMetadataFactory(new AttributeLoader()));
    }

    #[DataProvider('provideGuessableProperties')]
    public function testGuessType(string $property, string $entityClass)
    {
        $guess = $this->guesser->guessType(EntityExistsDto::class, $property);

        $this->assertSame(EntityIdentifierType::class, $guess->getType());
        $this->assertSame(['class' => $entityClass, 'em' => null], $guess->getOptions());
        $this->assertSame(Guess::MEDIUM_CONFIDENCE, $guess->getConfidence());
    }

    public static function provideGuessableProperties(): iterable
    {
        yield 'int identifier' => ['intId', SingleIntIdEntity::class];
        yield 'untyped property' => ['untypedId', SingleIntIdEntity::class];
        yield 'identifierField naming the identifier' => ['identifierFieldIsTheId', SingleIntIdEntity::class];
        yield 'string identifier' => ['stringId', SingleStringIdEntity::class];
        yield 'uuid identifier' => ['uuid', UuidIdEntity::class];
    }

    #[DataProvider('provideUnguessableProperties')]
    public function testNoGuess(string $property)
    {
        $this->assertNull($this->guesser->guessType(EntityExistsDto::class, $property));
        $this->assertNull($this->guesser->guessRequired(EntityExistsDto::class, $property));
    }

    public static function provideUnguessableProperties(): iterable
    {
        yield 'identifierField, its values would be disclosed' => ['name'];
        yield 'repository method' => ['repositoryMethod'];
        yield 'composite identifier' => ['compositeId'];
        yield 'association' => ['association'];
        yield 'property typed with a class' => ['uuidObject'];
        yield 'repeated constraint' => ['repeated'];
        yield 'unknown entity' => ['unknownEntity'];
        yield 'no constraint' => ['unconstrained'];
        yield 'unknown property' => ['unknownProperty'];
    }

    public function testNoGuessWhenTheRegistryCannotReflectTheEntity()
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willThrowException(new \ReflectionException('Class "DoesNotExist" does not exist'));
        $guesser = new EntityExistsTypeGuesser($registry, new LazyLoadingMetadataFactory(new AttributeLoader()));

        $this->assertNull($guesser->guessType(EntityExistsDto::class, 'intId'));
    }

    public function testNoGuessWhenNoManagerHandlesTheEntity()
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $guesser = new EntityExistsTypeGuesser($registry, new LazyLoadingMetadataFactory(new AttributeLoader()));

        $this->assertNull($guesser->guessType(EntityExistsDto::class, 'intId'));
    }

    public function testGuessRequiredFollowsTheNullabilityOfTheProperty()
    {
        $this->assertEquals(new ValueGuess(false, Guess::MEDIUM_CONFIDENCE), $this->guesser->guessRequired(EntityExistsDto::class, 'intId'));
        $this->assertEquals(new ValueGuess(true, Guess::MEDIUM_CONFIDENCE), $this->guesser->guessRequired(EntityExistsDto::class, 'requiredIntId'));
        $this->assertNull($this->guesser->guessRequired(EntityExistsDto::class, 'untypedId'));
    }

    public function testNoLengthOrPatternGuess()
    {
        $this->assertNull($this->guesser->guessMaxLength(EntityExistsDto::class, 'intId'));
        $this->assertNull($this->guesser->guessPattern(EntityExistsDto::class, 'intId'));
    }

    private function createRegistry(): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->em);
        $registry->method('getManagerForClass')->willReturn($this->em);

        return $registry;
    }
}
