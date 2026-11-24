<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Input;

use Symfony\Component\TuiApp\Exception\InvalidArgumentException;

/**
 * Encodes a key id, like "ctrl+s" or "shift+up", into the bytes a terminal
 * with the Kitty keyboard protocol sends for it, which Tui parses.
 *
 * A host terminal reports the Kitty protocol as active, so that every
 * modifier combination has an encoding.
 *
 * @internal
 */
final class KeyEncoder
{
    private const MODIFIERS = [
        'shift' => 1,
        'alt' => 2,
        'ctrl' => 4,
        'super' => 8,
        'hyper' => 16,
        'meta' => 32,
    ];

    private const CODEPOINTS = [
        'escape' => 27,
        'tab' => 9,
        'enter' => 13,
        'space' => 32,
        'backspace' => 127,
    ];

    private const PLAIN = [
        'escape' => "\x1b[27u",
        'tab' => "\t",
        'enter' => "\r",
        'space' => ' ',
        'backspace' => "\x7f",
    ];

    private const LETTERS = [
        'up' => 'A',
        'down' => 'B',
        'right' => 'C',
        'left' => 'D',
        'home' => 'H',
        'end' => 'F',
        'f1' => 'P',
        'f2' => 'Q',
        'f3' => 'R',
        'f4' => 'S',
    ];

    private const TILDE_CODES = [
        'insert' => 2,
        'delete' => 3,
        'page_up' => 5,
        'page_down' => 6,
        'f5' => 15,
        'f6' => 17,
        'f7' => 18,
        'f8' => 19,
        'f9' => 20,
        'f10' => 21,
        'f11' => 23,
        'f12' => 24,
    ];

    /**
     * @param string|null $text The text the key types, if any; typed as is when no modifier other than shift is held
     */
    public static function encode(string $keyId, ?string $text = null): string
    {
        [$key, $modifiers] = self::split($keyId);

        // A key types printable text; control bytes would be read as other keys
        if ('' === $text || null !== $text && preg_match('/[\x00-\x1f\x7f]/', $text)) {
            $text = null;
        }

        if (null !== $text && 0 === ($modifiers & ~self::MODIFIERS['shift'])) {
            return $text;
        }

        $parameter = 1 + $modifiers;

        if (isset(self::CODEPOINTS[$key])) {
            return 0 === $modifiers ? self::PLAIN[$key] : \sprintf("\x1b[%d;%du", self::CODEPOINTS[$key], $parameter);
        }

        // Kitty sends a modified F3 as CSI 13~, since CSI 1;<mod>R is also a cursor position report
        if ('f3' === $key && 0 !== $modifiers) {
            return \sprintf("\x1b[13;%d~", $parameter);
        }

        if (isset(self::LETTERS[$key])) {
            if (0 === $modifiers) {
                return (str_starts_with($key, 'f') ? "\x1bO" : "\x1b[").self::LETTERS[$key];
            }

            return \sprintf("\x1b[1;%d%s", $parameter, self::LETTERS[$key]);
        }

        if (isset(self::TILDE_CODES[$key])) {
            return 0 === $modifiers ? \sprintf("\x1b[%d~", self::TILDE_CODES[$key]) : \sprintf("\x1b[%d;%d~", self::TILDE_CODES[$key], $parameter);
        }

        if (1 !== mb_strlen($key)) {
            throw new InvalidArgumentException(\sprintf('The key id "%s" names an unknown key "%s".', $keyId, $key));
        }

        // Tui only knows ASCII key ids: another character is typed text, whatever the modifiers
        if (0 === $modifiers || \strlen($key) > 1) {
            if (preg_match('/[\x00-\x1f\x7f]/', $key)) {
                throw new InvalidArgumentException(\sprintf('The key id "%s" names a control character.', $keyId));
            }

            return $text ?? $key;
        }

        return \sprintf("\x1b[%d;%du", mb_ord(mb_strtolower($key)), $parameter);
    }

    /**
     * @return array{string, int}
     */
    private static function split(string $keyId): array
    {
        if ('' === $keyId) {
            throw new InvalidArgumentException('A key id cannot be empty.');
        }

        // "+" alone, or ending a combination like "ctrl++", is the plus key
        $parts = str_ends_with($keyId, '++') || '+' === $keyId
            ? [...array_filter(explode('+', substr($keyId, 0, -1)), static fn (string $part): bool => '' !== $part), '+']
            : explode('+', $keyId);
        $key = array_pop($parts);
        $modifiers = 0;
        foreach ($parts as $part) {
            $modifiers |= self::MODIFIERS[$part] ?? throw new InvalidArgumentException(\sprintf('The key id "%s" names an unknown modifier "%s".', $keyId, $part));
        }

        return [$key, $modifiers];
    }
}
