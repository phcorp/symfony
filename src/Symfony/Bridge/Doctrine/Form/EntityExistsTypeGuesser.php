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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\MappingException;
use Symfony\Bridge\Doctrine\Form\Type\EntityIdentifierType;
use Symfony\Bridge\Doctrine\Validator\Constraints\EntityExists;
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
 * Guesses an {@see EntityIdentifierType} for the properties constrained with {@see EntityExists}.
 *
 * Only lookups by a single scalar identifier are guessed: listing the values of
 * another field, such as an email or a code, would disclose them.
 */
final class EntityExistsTypeGuesser implements FormTypeGuesserInterface
{
    private const IDENTIFIER_TYPES = [Types::INTEGER, Types::SMALLINT, Types::BIGINT, Types::STRING, Types::ASCII_STRING, Types::GUID, 'uuid', 'ulid'];

    /**
     * @var array<string, array{0: EntityExists, 1: \ReflectionType|null}|false>
     */
    private array $cache = [];

    public function __construct(
        private ManagerRegistry $registry,
        private MetadataFactoryInterface $metadataFactory,
    ) {
    }

    public function guessType(string $class, string $property): ?TypeGuess
    {
        if (!$guessable = $this->getGuessable($class, $property)) {
            return null;
        }

        $constraint = $guessable[0];

        // above the LOW confidence text guesses, below the HIGH confidence ones of more specific constraints
        return new TypeGuess(EntityIdentifierType::class, ['class' => $constraint->entityClass, 'em' => $constraint->em], Guess::MEDIUM_CONFIDENCE);
    }

    public function guessRequired(string $class, string $property): ?ValueGuess
    {
        if (!$guessable = $this->getGuessable($class, $property)) {
            return null;
        }

        // a property refusing null cannot take the empty choice
        return null !== $guessable[1] ? new ValueGuess(!$guessable[1]->allowsNull(), Guess::MEDIUM_CONFIDENCE) : null;
    }

    public function guessMaxLength(string $class, string $property): ?ValueGuess
    {
        return null;
    }

    public function guessPattern(string $class, string $property): ?ValueGuess
    {
        return null;
    }

    /**
     * @return array{0: EntityExists, 1: \ReflectionType|null}|false
     */
    private function getGuessable(string $class, string $property): array|false
    {
        return $this->cache[$class.'::'.$property] ??= $this->resolveGuessable($class, $property);
    }

    /**
     * @return array{0: EntityExists, 1: \ReflectionType|null}|false
     */
    private function resolveGuessable(string $class, string $property): array|false
    {
        if (!$constraint = $this->getConstraint($class, $property)) {
            return false;
        }

        // the choices are scalars, a property typed with a class or an enum cannot hold them
        // a constraint put on a getter says nothing about the type of the property
        $propertyType = property_exists($class, $property) ? (new \ReflectionProperty($class, $property))->getType() : null;
        foreach ($propertyType instanceof \ReflectionUnionType ? $propertyType->getTypes() : array_filter([$propertyType]) as $type) {
            if (!$type instanceof \ReflectionNamedType || !\in_array($type->getName(), ['int', 'string', 'mixed', 'null'], true)) {
                return false;
            }
        }

        try {
            $manager = $constraint->em ? $this->registry->getManager($constraint->em) : $this->registry->getManagerForClass($constraint->entityClass);
            $classMetadata = $manager?->getClassMetadata($constraint->entityClass);
        } catch (\InvalidArgumentException|\ReflectionException|MappingException) {
            return false;
        }

        if (!$classMetadata || !$this->isScalarIdentifier($constraint, $classMetadata)) {
            return false;
        }

        return [$constraint, $propertyType];
    }

    private function getConstraint(string $class, string $property): ?EntityExists
    {
        $classMetadata = $this->metadataFactory->getMetadataFor($class);

        if (!$classMetadata instanceof ClassMetadataInterface || !$classMetadata->hasPropertyMetadata($property)) {
            return null;
        }

        $found = null;
        foreach ($classMetadata->getPropertyMetadata($property) as $memberMetadata) {
            foreach ($memberMetadata->getConstraints() as $constraint) {
                if (!$constraint instanceof EntityExists) {
                    continue;
                }

                // the constraint is repeatable, a single choice cannot satisfy several lookups
                if ($found) {
                    return null;
                }

                $found = $constraint;
            }
        }

        return $found;
    }

    private function isScalarIdentifier(EntityExists $constraint, ClassMetadata $classMetadata): bool
    {
        // a custom repository method cannot be turned into a list of values
        if ($constraint->repositoryMethod) {
            return false;
        }

        $identifierFieldNames = $classMetadata->getIdentifierFieldNames();

        if (1 !== \count($identifierFieldNames) || ($constraint->identifierField && $constraint->identifierField !== $identifierFieldNames[0])) {
            return false;
        }

        $field = $identifierFieldNames[0];

        if (!$classMetadata->hasField($field) || !\in_array($classMetadata->getTypeOfField($field), self::IDENTIFIER_TYPES, true)) {
            return false;
        }

        if (!method_exists($classMetadata, 'getFieldMapping')) {
            return true;
        }

        $mapping = $classMetadata->getFieldMapping($field);

        return null === ($mapping instanceof FieldMapping ? $mapping->enumType : ($mapping['enumType'] ?? null));
    }
}
