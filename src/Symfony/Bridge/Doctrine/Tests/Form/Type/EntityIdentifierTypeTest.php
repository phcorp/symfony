<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Tests\Form\Type;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Form\EntityExistsTypeGuesser;
use Symfony\Bridge\Doctrine\Form\Type\EntityIdentifierType;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bridge\Doctrine\Middleware\Debug\Middleware;
use Symfony\Bridge\Doctrine\Tests\DoctrineTestHelper;
use Symfony\Bridge\Doctrine\Tests\Fixtures\CompositeIntIdEntity;
use Symfony\Bridge\Doctrine\Tests\Fixtures\EntityExistsDto;
use Symfony\Bridge\Doctrine\Tests\Fixtures\SingleIntIdEntity;
use Symfony\Bridge\Doctrine\Tests\Fixtures\SingleIntIdNoToStringEntity;
use Symfony\Bridge\Doctrine\Tests\Fixtures\SingleStringIdEntity;
use Symfony\Bridge\Doctrine\Tests\Fixtures\UuidIdEntity;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\EntityExists;
use Symfony\Bridge\Doctrine\Validator\Constraints\EntityExistsValidator;
use Symfony\Component\Form\ChoiceList\Factory\Cache\AbstractStaticOption;
use Symfony\Component\Form\Exception\RuntimeException;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;

class EntityIdentifierTypeTest extends TestCase
{
    private ?string $previousUuidType = null;
    private DebugDataHolder $queries;
    private EntityManager $em;
    private EntityIdentifierType $type;
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        // other tests register "uuid" with another class, and the registry is global
        $this->previousUuidType = Type::hasType('uuid') ? Type::getType('uuid')::class : null;
        $this->previousUuidType ? Type::overrideType('uuid', UuidType::class) : Type::addType('uuid', UuidType::class);

        $config = DoctrineTestHelper::createTestConfiguration();
        $config->setMiddlewares([new Middleware($this->queries = new DebugDataHolder(), null)]);
        $this->em = DoctrineTestHelper::createTestEntityManager($config);
        (new SchemaTool($this->em))->createSchema([
            $this->em->getClassMetadata(SingleIntIdEntity::class),
            $this->em->getClassMetadata(SingleStringIdEntity::class),
            $this->em->getClassMetadata(SingleIntIdNoToStringEntity::class),
            $this->em->getClassMetadata(UuidIdEntity::class),
        ]);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->em);
        $registry->method('getManagerForClass')->willReturn($this->em);

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([EntityExistsValidator::class => new EntityExistsValidator($registry)]))
            ->getValidator();

        $this->factory = Forms::createFormFactoryBuilder()
            ->addType($this->type = new EntityIdentifierType($registry))
            ->addTypeGuesser(new EntityExistsTypeGuesser($registry, $validator))
            ->addExtension(new ValidatorExtension($validator))
            ->getFormFactory();
    }

    protected function tearDown(): void
    {
        $this->em->close();

        if ($this->previousUuidType) {
            Type::overrideType('uuid', $this->previousUuidType);
        }
    }

    public function testChoicesAreTheIdentifiersLabelledWithTheEntities()
    {
        $this->persist(new SingleIntIdEntity(1, 'Foo'), new SingleIntIdEntity(2, 'Bar'));

        $view = $this->factory->create(EntityIdentifierType::class, null, ['class' => SingleIntIdEntity::class])->createView();

        $this->assertSame(['1' => 'Foo', '2' => 'Bar'], $this->getChoiceLabels($view->vars['choices']));
        $this->assertSame('', $view->vars['placeholder']);
    }

    public function testLabelIsTheIdentifierWhenTheEntityIsNotStringable()
    {
        $this->persist(new SingleIntIdNoToStringEntity(1, 'Foo'));

        $view = $this->factory->create(EntityIdentifierType::class, null, ['class' => SingleIntIdNoToStringEntity::class])->createView();

        $this->assertSame(['1' => '1'], $this->getChoiceLabels($view->vars['choices']));
    }

    public function testBuildingAndSubmittingDoesNotQueryTheEntities()
    {
        $this->persist(new SingleIntIdEntity(1, 'Foo'));
        $this->queries->reset();

        $form = $this->factory->create(EntityIdentifierType::class, 1, ['class' => SingleIntIdEntity::class]);
        $form->submit('1');

        $this->assertSame([], $this->queries->getData());
        $this->assertSame(1, $form->getData());
    }

    public function testEntitiesAreLoadedOnceForSeveralForms()
    {
        $this->persist(new SingleIntIdEntity(1, 'Foo'));
        $this->queries->reset();

        $this->factory->create(EntityIdentifierType::class, null, ['class' => SingleIntIdEntity::class])->createView();
        $this->factory->create(EntityIdentifierType::class, null, ['class' => SingleIntIdEntity::class])->createView();

        $this->assertCount(1, $this->queries->getData()['default']);
    }

    public function testResetReloadsTheEntities()
    {
        $this->persist(new SingleIntIdEntity(1, 'Foo'));
        $this->queries->reset();

        $this->factory->create(EntityIdentifierType::class, null, ['class' => SingleIntIdEntity::class])->createView();

        // without resetting the type, its loader keeps the entities after the choice list caches are reset
        $this->createFactoryWithResetChoiceListCaches()->create(EntityIdentifierType::class, null, ['class' => SingleIntIdEntity::class])->createView();
        $this->assertCount(1, $this->queries->getData()['default']);

        $this->type->reset();
        $this->createFactoryWithResetChoiceListCaches()->create(EntityIdentifierType::class, null, ['class' => SingleIntIdEntity::class])->createView();
        $this->assertCount(2, $this->queries->getData()['default']);
    }

    public function testSubmittedValuesArePassedThroughToTheModel()
    {
        $form = $this->factory->create(EntityIdentifierType::class, null, ['class' => SingleIntIdEntity::class]);
        $form->submit('999');

        $this->assertTrue($form->isSynchronized());
        $this->assertSame(999, $form->getData());
    }

    public function testEmptySubmissionIsNull()
    {
        $form = $this->factory->create(EntityIdentifierType::class, 1, ['class' => SingleIntIdEntity::class]);
        $form->submit('');

        $this->assertTrue($form->isSynchronized());
        $this->assertNull($form->getData());
    }

    public function testNumericStringIdentifiersStayStrings()
    {
        $this->persist(new SingleStringIdEntity('123', 'Foo'));

        $form = $this->factory->create(EntityIdentifierType::class, null, ['class' => SingleStringIdEntity::class]);
        $view = $form->createView();
        $form->submit('123');

        $this->assertSame(['123'], array_map(static fn ($choice) => $choice->data, array_values($view->vars['choices'])));
        $this->assertSame('123', $form->getData());
    }

    public function testUidInAnotherFormatSelectsItsChoice()
    {
        $id = Uuid::fromString('ec562e21-1fc8-4e55-8de7-a42389ac75c5');
        $this->persist(new UuidIdEntity($id));

        $view = $this->factory->create(EntityIdentifierType::class, strtoupper((string) $id), ['class' => UuidIdEntity::class])->createView();

        $this->assertSame((string) $id, $view->vars['value']);
        $this->assertSame([(string) $id], array_keys($this->getChoiceLabels($view->vars['choices'])));
    }

    public function testExplicitChoicesRestrictWhatCanBeSubmitted()
    {
        $this->persist(new SingleIntIdEntity(1, 'Foo'), new SingleIntIdEntity(2, 'Bar'));

        $form = $this->factory->create(EntityIdentifierType::class, null, ['class' => SingleIntIdEntity::class, 'choices' => ['Foo' => 1]]);
        $form->submit('2');

        $this->assertFalse($form->isSynchronized());
    }

    public function testCompositeIdentifierIsRejected()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must have a single identifier field');

        $this->factory->create(EntityIdentifierType::class, null, ['class' => CompositeIntIdEntity::class]);
    }

    public function testGuessedFieldReportsUnknownIdentifiersWithTheConstraintError()
    {
        $this->persist(new SingleIntIdEntity(1, 'Foo'));

        $dto = new EntityExistsDto();
        $form = $this->factory->createBuilder(FormType::class, $dto)->add('intId')->getForm();
        $form->submit(['intId' => '999']);

        $this->assertInstanceOf(EntityIdentifierType::class, $form->get('intId')->getConfig()->getType()->getInnerType());
        $this->assertTrue($form->isSynchronized());
        $this->assertSame(999, $dto->intId);

        $errors = $form->get('intId')->getErrors();
        $this->assertCount(1, $errors);
        $this->assertSame(EntityExists::ENTITY_NOT_FOUND_ERROR, $errors[0]->getCause()->getCode());
    }

    public function testGuessedFieldMapsAnExistingIdentifier()
    {
        $this->persist(new SingleIntIdEntity(1, 'Foo'), new SingleIntIdEntity(2, 'Bar'));

        $dto = new EntityExistsDto();
        $form = $this->factory->createBuilder(FormType::class, $dto)->add('intId')->add('requiredIntId')->getForm();

        $this->assertTrue($form->get('requiredIntId')->isRequired());
        $this->assertFalse($form->get('intId')->isRequired());

        $form->submit(['intId' => '2', 'requiredIntId' => '1']);

        $this->assertTrue($form->isValid());
        $this->assertSame(2, $dto->intId);
        $this->assertSame(1, $dto->requiredIntId);
    }

    /**
     * Mimics what "kernel.reset" does to the choice list factory between two requests.
     */
    private function createFactoryWithResetChoiceListCaches(): FormFactoryInterface
    {
        // the factory comes with a new choice list cache, the static cache of the choice list options is shared
        AbstractStaticOption::reset();

        return Forms::createFormFactoryBuilder()->addType($this->type)->getFormFactory();
    }

    private function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }

        $this->em->flush();
        $this->em->clear();
    }

    private function getChoiceLabels(array $choiceViews): array
    {
        $labels = [];
        foreach ($choiceViews as $choiceView) {
            $labels[$choiceView->value] = $choiceView->label;
        }

        return $labels;
    }
}
