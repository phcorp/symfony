<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\TuiHtml\Content;

use Symfony\Component\HtmlRenderer\Content\ContentLayout;
use Symfony\Component\HtmlRenderer\Content\FragmentableContentInterface;
use Symfony\Component\HtmlRenderer\Content\IntrinsicSize;
use Symfony\Component\HtmlRenderer\Content\ReplacedContentInterface;

/** @internal */
final class FragmentableInlineImage implements FragmentableContentInterface
{
    private bool $prepared = false;
    /** @var list<ReplacedContentInterface>|null */
    private ?array $fragments = null;

    public function __construct(private readonly ReplacedContentInterface $whole, private readonly int $columns, private readonly float $cellWidth, private readonly float $cellHeight)
    {
    }

    public function getIntrinsicSize(): IntrinsicSize
    {
        return $this->whole->getIntrinsicSize();
    }

    public function layout(float $width, float $availableHeight): ContentLayout
    {
        return $this->whole->layout($width, $availableHeight);
    }

    /** @return list<ReplacedContentInterface>|null */
    public function getInlineFragments(): ?array
    {
        if (!$this->prepared) {
            $natural = $this->whole->getIntrinsicSize();
            $this->fragments = InlineImageCellContent::split($this->whole->layout($natural->preferredWidth, $natural->height), $this->columns, $this->cellWidth, $this->cellHeight);
            $this->prepared = true;
        }

        return $this->fragments;
    }
}
