<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HtmlRenderer\Content;

/**
 * Supplies a natural inline word with optional atomic emergency fragments.
 *
 * Intrinsic sizing and ordinary layout never require fragmentation. The complete provider remains usable when fragmentation is unsupported. Returned pieces are stable, ordered atomic providers in the same coordinate units as this provider.
 *
 * Their natural preferred sizes describe consecutive pieces of the complete word; this capability does not make flexible controls shrink collectively. The complete provider's minimum width describes the largest piece minimum. Returning null preserves complete atomic layout when emergency fragmentation is unavailable.
 *
 * Pieces are treated atomically even when they implement this interface themselves. Implementations must bound the returned list to 100000 pieces.
 *
 * @experimental
 */
interface FragmentableContentInterface extends ReplacedContentInterface
{
    /** @return non-empty-list<ReplacedContentInterface>|null */
    public function getInlineFragments(): ?array;
}
