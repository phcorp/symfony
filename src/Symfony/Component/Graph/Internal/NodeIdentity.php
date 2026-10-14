<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Graph\Internal;

/**
 * @internal
 */
final class NodeIdentity
{
    public static function key(mixed $node): string
    {
        if (\is_int($node)) {
            return 'i'.$node;
        }
        if (\is_string($node)) {
            return 's'.$node;
        }

        throw new \TypeError(\sprintf('Graph nodes must be integers or strings, "%s" given.', get_debug_type($node)));
    }
}
