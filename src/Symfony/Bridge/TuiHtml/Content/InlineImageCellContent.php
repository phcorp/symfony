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
use Symfony\Component\HtmlRenderer\Content\IntrinsicSize;
use Symfony\Component\HtmlRenderer\Content\ReplacedContentInterface;
use Symfony\Component\HtmlRenderer\Paint\DisplayList;

/** @internal */
final class InlineImageCellContent implements ReplacedContentInterface
{
    private function __construct(private readonly float $width, private readonly float $height, private readonly DisplayList $display)
    {
    }

    public static function split(ContentLayout $content, int $columns, float $width, float $height): ?array
    {
        if ($columns > 100000) {
            throw new \LengthException('Inline image fragmentation exceeds the content count limit.');
        }
        if ($columns < 1 || null !== $content->background) {
            return null;
        }
        $cells = [];
        $stack = [];
        foreach ($content->displayList as $operation) {
            if ('push-opacity' === $operation['type']) {
                $stack[] = $operation;
                continue;
            }
            if ('pop-opacity' === $operation['type']) {
                array_pop($stack);
                continue;
            }
            if (!\in_array($operation['type'], ['text', 'rectangle'], true) || 'text' === $operation['type'] && (' ' === $operation['text'] || 1 !== preg_match_all('/\X/u', $operation['text']))) {
                return null;
            }
            $column = (int) round($operation['x'] / $width);
            if ($column < 0 || $column >= $columns || abs($operation['x'] - $column * $width) > 0.000001 || 0.0 !== (float) $operation['y'] || (float) $operation['width'] !== $width || (float) $operation['height'] !== $height) {
                return null;
            }
            $operation['x'] = 0.0;
            $cells[$column] ??= [];
            array_push($cells[$column], ...$stack);
            $cells[$column][] = $operation;
            foreach (array_reverse($stack) as $_) {
                $cells[$column][] = ['type' => 'pop-opacity'];
            }
        }
        $result = [];
        for ($column = 0; $column < $columns; ++$column) {
            if (!isset($cells[$column])) {
                return null;
            }
            $result[] = new self($width, $height, new DisplayList($cells[$column]));
        }

        return $result;
    }

    public function getIntrinsicSize(): IntrinsicSize
    {
        return new IntrinsicSize($this->width, $this->width, $this->height);
    }

    public function layout(float $width, float $availableHeight): ContentLayout
    {
        return new ContentLayout($this->height, $this->display);
    }
}
