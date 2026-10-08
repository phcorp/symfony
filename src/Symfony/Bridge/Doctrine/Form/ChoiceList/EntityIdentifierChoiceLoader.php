<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Form\ChoiceList;

use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Form\ChoiceList\Loader\AbstractChoiceLoader;

/**
 * Loads the identifier values of the entities of a class as choices.
 *
 * The full list is only loaded to render the choices. Submitted values are
 * passed through without querying, so that checking that they reference an
 * entity is left to the EntityExists constraint.
 *
 * @internal
 */
final class EntityIdentifierChoiceLoader extends AbstractChoiceLoader
{
    /**
     * @var array<string, string>
     */
    private array $labels = [];

    public function __construct(
        private readonly ObjectManager $manager,
        private readonly string $class,
        private readonly string $field,
    ) {
    }

    /**
     * Returns the label of the entity referenced by a choice, or the choice itself when the entity cannot be cast to a string.
     */
    public function getLabel(int|string $choice): string
    {
        return $this->labels[(string) $choice] ?? (string) $choice;
    }

    protected function loadChoices(): iterable
    {
        $classMetadata = $this->manager->getClassMetadata($this->class);
        $choices = [];

        foreach ($this->manager->getRepository($this->class)->findAll() as $entity) {
            $choice = $classMetadata->getIdentifierValues($entity)[$this->field];
            $choice = $choice instanceof \Stringable ? (string) $choice : $choice;

            $this->labels[(string) $choice] = $entity instanceof \Stringable ? (string) $entity : (string) $choice;
            $choices[] = $choice;
        }

        return $choices;
    }

    protected function doLoadChoicesForValues(array $values, ?callable $value): array
    {
        $choices = [];
        $isInteger = \in_array($this->manager->getClassMetadata($this->class)->getTypeOfField($this->field), [Types::INTEGER, Types::SMALLINT, Types::BIGINT], true);

        foreach ($values as $i => $submitted) {
            // the empty value is the placeholder, not a choice
            if ('' === $submitted || null === $submitted) {
                continue;
            }

            $choices[$i] = $isInteger && (string) (int) $submitted === $submitted ? (int) $submitted : $submitted;
        }

        return $choices;
    }

    protected function doLoadValuesForChoices(array $choices): array
    {
        return array_map(static fn ($choice) => null === $choice ? '' : (string) $choice, $choices);
    }
}
