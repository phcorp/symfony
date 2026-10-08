<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Form\Type;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Symfony\Bridge\Doctrine\Form\ChoiceList\EntityIdentifierChoiceLoader;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\ChoiceList\ChoiceList;
use Symfony\Component\Form\ChoiceList\Factory\CachingFactoryDecorator;
use Symfony\Component\Form\Exception\RuntimeException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A choice of entities whose model data is their identifier, for scalar properties
 * such as the ones constrained with EntityExists.
 *
 * Submitted values are not checked against the entities, which is the job of the
 * EntityExists constraint, unless the "choices" option restricts them.
 */
class EntityIdentifierType extends AbstractType implements ResetInterface
{
    /**
     * @var array<string, EntityIdentifierChoiceLoader>
     */
    private array $choiceLoaders = [];

    public function __construct(
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'em' => null,
            'choices' => null,
            'choice_loader' => function (Options $options) {
                // explicit choices restrict what can be submitted
                if (null !== $options['choices']) {
                    return null;
                }

                return ChoiceList::loader($this, $this->getChoiceLoader($options['em'], $options['class']), [$options['em'], $options['class']]);
            },
            'choice_label' => function (Options $options) {
                if (null !== $options['choices']) {
                    return null;
                }

                return ChoiceList::label($this, $this->getChoiceLoader($options['em'], $options['class'])->getLabel(...), [$options['em'], $options['class']]);
            },
            'choice_value' => function (Options $options) {
                $uidClass = match ($options['em']->getClassMetadata($options['class'])->getTypeOfField($this->getIdentifierField($options['em'], $options['class']))) {
                    'uuid' => Uuid::class,
                    'ulid' => Ulid::class,
                    default => null,
                };

                return ChoiceList::value($this, static function (mixed $choice) use ($uidClass): string {
                    if (null === $choice) {
                        return '';
                    }

                    // a uid given in any of its formats selects the choice holding its canonical form
                    if ($uidClass && \is_string($choice)) {
                        try {
                            return (string) $uidClass::fromString($choice);
                        } catch (\InvalidArgumentException) {
                        }
                    }

                    return (string) $choice;
                }, $uidClass);
            },
            'choice_translation_domain' => false,
            'placeholder' => '',
        ]);

        $resolver->setRequired(['class']);

        $resolver->setNormalizer('em', function (Options $options, $em): ObjectManager {
            if ($em instanceof ObjectManager) {
                return $em;
            }

            if (null !== $em) {
                return $this->registry->getManager($em);
            }

            return $this->registry->getManagerForClass($options['class']) ?? throw new RuntimeException(\sprintf('Class "%s" seems not to be a managed Doctrine entity. Did you forget to map it?', $options['class']));
        });

        $resolver->setAllowedTypes('class', 'string');
        $resolver->setAllowedTypes('em', ['null', 'string', ObjectManager::class]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function reset(): void
    {
        $this->choiceLoaders = [];
    }

    private function getChoiceLoader(ObjectManager $manager, string $class): EntityIdentifierChoiceLoader
    {
        return $this->choiceLoaders[CachingFactoryDecorator::generateHash([$manager, $class])] ??= new EntityIdentifierChoiceLoader($manager, $class, $this->getIdentifierField($manager, $class));
    }

    private function getIdentifierField(ObjectManager $manager, string $class): string
    {
        $identifierFieldNames = $manager->getClassMetadata($class)->getIdentifierFieldNames();

        if (1 !== \count($identifierFieldNames)) {
            throw new RuntimeException(\sprintf('Entity "%s" must have a single identifier field to be used with "%s".', $class, self::class));
        }

        return $identifierFieldNames[0];
    }
}
