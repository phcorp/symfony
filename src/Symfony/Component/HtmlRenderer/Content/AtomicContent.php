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

/** @internal */
final class AtomicContent implements ReplacedContentInterface
{
    public function __construct(private readonly ReplacedContentInterface $content)
    {
    }

    public function getIntrinsicSize(): IntrinsicSize
    {
        return $this->content->getIntrinsicSize();
    }

    public function layout(float $width, float $availableHeight): ContentLayout
    {
        return $this->content->layout($width, $availableHeight);
    }
}
