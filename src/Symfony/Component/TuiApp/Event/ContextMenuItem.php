<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Event;

/**
 * An item of a context menu, see ContextMenuEvent.
 *
 * @experimental
 */
final class ContextMenuItem
{
    private bool $separator = false;

    /**
     * @param (\Closure(): void)|null $action What choosing it does
     * @param 'paste'|null            $role   paste: the host pastes the text of its clipboard instead, like its Paste
     */
    public function __construct(
        private readonly string $label,
        private readonly ?\Closure $action = null,
        private readonly bool $enabled = true,
        private readonly ?string $role = null,
    ) {
    }

    /**
     * A line between the items before and after it.
     */
    public static function separator(): self
    {
        $separator = new self('');
        $separator->separator = true;

        return $separator;
    }

    public function isSeparator(): bool
    {
        return $this->separator;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return (\Closure(): void)|null
     */
    public function getAction(): ?\Closure
    {
        return $this->action;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return 'paste'|null
     */
    public function getRole(): ?string
    {
        return $this->role;
    }
}
