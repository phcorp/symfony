<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\JsonStreamer\DataModel\Write;

use Symfony\Component\TypeInfo\Type\BackedEnumType;

/**
 * Represents a backed enum in the data model graph representation.
 *
 * Backed enums are leaves in the data model tree.
 *
 * @author Mathias Arlaud <mathias.arlaud@gmail.com>
 *
 * @internal
 */
final class BackedEnumNode implements DataModelNodeInterface
{
    public function __construct(
        private string $accessor,
        private BackedEnumType $type,
    ) {
    }

    public function withAccessor(string $accessor): self
    {
        return new self($accessor, $this->type);
    }

    public function getIdentifier(): string
    {
        return (string) $this->getType();
    }

    public function getAccessor(): string
    {
        return $this->accessor;
    }

    public function getType(): BackedEnumType
    {
        return $this->type;
    }
}
