<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Tests\Fixtures;

use Symfony\Bridge\Doctrine\Validator\Constraints\EntityExists;

class EntityExistsDto
{
    #[EntityExists(entityClass: SingleIntIdEntity::class)]
    public ?int $intId = null;

    #[EntityExists(entityClass: SingleStringIdEntity::class)]
    public ?string $stringId = null;

    #[EntityExists(entityClass: SingleIntIdEntity::class, identifierField: 'name')]
    public ?string $name = null;

    #[EntityExists(entityClass: SingleIntIdNoToStringEntity::class)]
    public ?int $noToStringId = null;

    #[EntityExists(entityClass: UuidIdEntity::class)]
    public ?string $uuid = null;

    #[EntityExists(entityClass: SingleIntIdEntity::class, repositoryMethod: 'findByCustom')]
    public ?int $repositoryMethod = null;

    #[EntityExists(entityClass: CompositeIntIdEntity::class)]
    public ?int $compositeId = null;

    #[EntityExists(entityClass: AssociationEntity::class, identifierField: 'single')]
    public ?int $association = null;

    #[EntityExists(entityClass: SingleIntIdEntity::class, identifierField: 'unknown')]
    public ?string $unknownField = null;

    public ?string $unconstrained = null;
}
