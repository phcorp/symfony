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

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Form\EntityExistsTypeGuesser;
use Symfony\Bridge\Doctrine\Tests\DoctrineTestHelper;
use Symfony\Bridge\Doctrine\Tests\Fixtures\AssociationEntity;
use Symfony\Bridge\Doctrine\Tests\Fixtures\CompositeIntIdEntity;
use Symfony\Bridge\Doctrine\Tests\Fixtures\EntityExistsDto;
use Symfony\Bridge\Doctrine\Tests\Fixtures\SingleIntIdEntity;
use Symfony\Bridge\Doctrine\Tests\Fixtures\SingleIntIdNoToStringEntity;
use Symfony\Bridge\Doctrine\Tests\Fixtures\SingleStringIdEntity;
use Symfony\Bridge\Doctrine\Tests\Fixtures\UuidIdEntity;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Form\ChoiceList\Loader\ChoiceLoaderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\Guess\Guess;
use Symfony\Component\Form\Guess\TypeGuess;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory;
use Symfony\Component\Validator\Mapping\Loader\AttributeLoader;

class EntityExistsTypeGuesserTest extends TestCase
{
    private EntityManager $em;
    private EntityExistsTypeGuesser $guesser;

    protected function setUp(): void
    {
        if (!Type::hasType('uuid')) {
            Type::addType('uuid', UuidType::class);
        }

        $this->em = DoctrineTestHelper::createTestEntityManager();
        (new SchemaTool($this->em))->createSchema([
            $this->em->getClassMetadata(SingleIntIdEntity::class),
            $this->em->getClassMetadata(SingleStringIdEntity::class),
            $this->em->getClassMetadata(SingleIntIdNoToStringEntity::class),
            $this->em->getClassMetadata(UuidIdEntity::class),
            $this->em->getClassMetadata(CompositeIntIdEntity::class),
            $this->em->getClassMetadata(AssociationEntity::class),
        ]);

        $this->guesser = new EntityExistsTypeGuesser($this->createRegistry(), new LazyLoadingMetadataFactory(new AttributeLoader()));
    }

    protected function tearDown(): void
    {
        $this->em->close();
    }

    public function testGuessChoiceTypeForTheIdentifier()
    {
        $this->em->persist(new SingleIntIdEntity(1, 'Foo'));
        $this->em->persist(new SingleIntIdEntity(2, 'Bar'));
        $this->em->flush();

        $guess = $this->guesser->guessType(EntityExistsDto::class, 'intId');

        $this->assertSame(ChoiceType::class, $guess->getType());
        $this->assertSame(Guess::HIGH_CONFIDENCE, $guess->getConfidence());
        $this->assertFalse($guess->getOptions()['choice_translation_domain']);
        $this->assertSame([1 => 'Foo', 2 => 'Bar'], $this->loadChoices($guess));
    }

    public function testGuessChoiceTypeForAStringIdentifier()
    {
        $this->em->persist(new SingleStringIdEntity('foo', 'Foo'));
        $this->em->flush();

        $this->assertSame(['foo' => 'Foo'], $this->loadChoices($this->guesser->guessType(EntityExistsDto::class, 'stringId')));
    }

    public function testGuessChoiceTypeForTheIdentifierField()
    {
        $this->em->persist(new SingleIntIdEntity(1, 'Foo'));
        $this->em->persist(new SingleIntIdEntity(2, 'Bar'));
        $this->em->flush();

        $guess = $this->guesser->guessType(EntityExistsDto::class, 'name');

        $this->assertSame(ChoiceType::class, $guess->getType());
        $this->assertSame(Guess::HIGH_CONFIDENCE, $guess->getConfidence());
        $this->assertSame(['Foo' => 'Foo', 'Bar' => 'Bar'], $this->loadChoices($guess));
    }

    public function testLabelFallsBackToTheValueWhenTheEntityIsNotStringable()
    {
        $this->em->persist(new SingleIntIdNoToStringEntity(1, 'Foo'));
        $this->em->flush();

        $this->assertSame([1 => '1'], $this->loadChoices($this->guesser->guessType(EntityExistsDto::class, 'noToStringId')));
    }

    public function testUidIdentifiersAreChoicesAsStrings()
    {
        $id = Uuid::fromString('ec562e21-1fc8-4e55-8de7-a42389ac75c5');
        $this->em->persist(new UuidIdEntity($id));
        $this->em->flush();

        $this->assertSame([(string) $id => (string) $id], $this->loadChoices($this->guesser->guessType(EntityExistsDto::class, 'uuid')));
    }

    #[DataProvider('provideUnguessableProperties')]
    public function testNoGuess(string $property)
    {
        $this->assertNull($this->guesser->guessType(EntityExistsDto::class, $property));
    }

    public static function provideUnguessableProperties(): iterable
    {
        yield 'repository method' => ['repositoryMethod'];
        yield 'composite identifier' => ['compositeId'];
        yield 'association' => ['association'];
        yield 'unknown field' => ['unknownField'];
        yield 'no constraint' => ['unconstrained'];
        yield 'unknown property' => ['unknownProperty'];
    }

    public function testNoGuessWhenNoManagerHandlesTheEntity()
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $guesser = new EntityExistsTypeGuesser($registry, new LazyLoadingMetadataFactory(new AttributeLoader()));

        $this->assertNull($guesser->guessType(EntityExistsDto::class, 'intId'));
    }

    public function testNoOtherGuesses()
    {
        $this->assertNull($this->guesser->guessRequired(EntityExistsDto::class, 'intId'));
        $this->assertNull($this->guesser->guessMaxLength(EntityExistsDto::class, 'intId'));
        $this->assertNull($this->guesser->guessPattern(EntityExistsDto::class, 'intId'));
    }

    public function testSubmittedValueIsMappedToTheScalarProperty()
    {
        $this->em->persist(new SingleIntIdEntity(1, 'Foo'));
        $this->em->persist(new SingleIntIdEntity(2, 'Bar'));
        $this->em->flush();

        $dto = new EntityExistsDto();
        $form = Forms::createFormFactoryBuilder()
            ->addTypeGuesser($this->guesser)
            ->getFormFactory()
            ->createBuilder(FormType::class, $dto)
            ->add('intId')
            ->add('name')
            ->getForm();

        $this->assertInstanceOf(ChoiceType::class, $form->get('intId')->getConfig()->getType()->getInnerType());
        $this->assertInstanceOf(ChoiceType::class, $form->get('name')->getConfig()->getType()->getInnerType());

        $form->submit(['intId' => '2', 'name' => 'Foo']);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame(2, $dto->intId);
        $this->assertSame('Foo', $dto->name);
    }

    /**
     * @return array<string, string> the choice labels, indexed by choice value
     */
    private function loadChoices(TypeGuess $guess): array
    {
        $options = $guess->getOptions();
        $this->assertInstanceOf(ChoiceLoaderInterface::class, $options['choice_loader']);

        $choices = [];
        foreach ($options['choice_loader']->loadChoiceList()->getChoices() as $choice) {
            $choices[$choice] = $options['choice_label']($choice);
        }

        return $choices;
    }

    private function createRegistry(): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->em);
        $registry->method('getManagerForClass')->willReturn($this->em);

        return $registry;
    }
}
