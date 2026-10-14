<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Graph\Exception;

class CycleDetectedException extends \RuntimeException implements ExceptionInterface
{
    /**
     * @param list<int|string> $cycle The cycle, including its repeated closing node
     */
    public function __construct(private readonly array $cycle)
    {
        parent::__construct(\sprintf('Cycle detected: "%s".', implode('" -> "', $cycle)));
    }

    /**
     * @return list<int|string>
     */
    public function getCycle(): array
    {
        return $this->cycle;
    }
}
