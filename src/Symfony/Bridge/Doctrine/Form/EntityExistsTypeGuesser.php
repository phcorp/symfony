<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Form;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\MappingException;
use Symfony\Bridge\Doctrine\Validator\Constraints\EntityExists;
use Symfony\Component\Form\ChoiceList\Loader\CallbackChoiceLoader;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormTypeGuesserInterface;
use Symfony\Component\Form\Guess\Guess;
use Symfony\Component\Form\Guess\TypeGuess;
use Symfony\Component\Form\Guess\ValueGuess;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface;

if (!interface_exists(FormTypeGuesserInterface::class)) {
    throw new \LogicException('You cannot use the "Symfony\Bridge\Doctrine\Form\EntityExistsTypeGuesser" class as the "symfony/form" package is not installed. Try running "composer require symfony/form".');
}

/**
 * Guesses a choice field for properties constrained with {@see EntityExists}.
 *
 * The choices are the values of the looked up field, so that the model data
 * stays the scalar the constraint validates, not the entity.
 */
final class EntityExistsTypeGuesser implements FormTypeGuesserInterface
{
    public function __construct(
        private ManagerRegistry $registry,
        private MetadataFactoryInterface $metadataFactory,
    ) {
    }

    public function guessType(string $class, string $property): ?TypeGuess
    {
        if (!$constraint = $this->getConstraint($class, $property)) {
            return null;
        }

        // a custom repository method cannot be turned into a list of values
        if ($constraint->repositoryMethod) {
            return null;
        }

        try {
            $em = $constraint->em ? $this->registry->getManager($constraint->em) : $this->registry->getManagerForClass($constraint->entityClass);
            $classMetadata = $em?->getClassMetadata($constraint->entityClass);
        } catch (\InvalidArgumentException|MappingException) {
            return null;
        }

        if (!$classMetadata instanceof ClassMetadata || !$field = $this->resolveField($constraint, $classMetadata)) {
            return null;
        }

        $labels = [];
        $loadValues = static function () use ($em, $constraint, $classMetadata, $field, &$labels): array {
            $labels = [];
            foreach ($em->getRepository($constraint->entityClass)->findAll() as $entity) {
                $value = $classMetadata->getFieldValue($entity, $field);
                $value = $value instanceof \Stringable ? (string) $value : $value;
                $labels[(string) $value] = $entity instanceof \Stringable ? (string) $entity : (string) $value;
            }

            return array_keys($labels);
        };

        return new TypeGuess(ChoiceType::class, [
            'choice_loader' => new CallbackChoiceLoader($loadValues),
            'choice_label' => static function ($value) use (&$labels): string {
                return $labels[(string) $value] ?? (string) $value;
            },
            'choice_translation_domain' => false,
        ], Guess::HIGH_CONFIDENCE);
    }

    public function guessRequired(string $class, string $property): ?ValueGuess
    {
        // null and '' are valid for EntityExists, NotBlank decides whether a value is required
        return null;
    }

    public function guessMaxLength(string $class, string $property): ?ValueGuess
    {
        return null;
    }

    public function guessPattern(string $class, string $property): ?ValueGuess
    {
        return null;
    }

    private function getConstraint(string $class, string $property): ?EntityExists
    {
        $classMetadata = $this->metadataFactory->getMetadataFor($class);

        if (!$classMetadata instanceof ClassMetadataInterface || !$classMetadata->hasPropertyMetadata($property)) {
            return null;
        }

        foreach ($classMetadata->getPropertyMetadata($property) as $memberMetadata) {
            foreach ($memberMetadata->getConstraints() as $constraint) {
                if ($constraint instanceof EntityExists) {
                    return $constraint;
                }
            }
        }

        return null;
    }

    private function resolveField(EntityExists $constraint, ClassMetadata $classMetadata): ?string
    {
        if (!$field = $constraint->identifierField) {
            $identifierFieldNames = $classMetadata->getIdentifierFieldNames();

            return 1 === \count($identifierFieldNames) && $classMetadata->hasField($identifierFieldNames[0]) ? $identifierFieldNames[0] : null;
        }

        // an association holds entities, not values a choice field could submit
        return $classMetadata->hasField($field) ? $field : null;
    }
}
