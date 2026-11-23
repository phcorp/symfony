<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Protocol;

use Symfony\Component\TuiApp\Exception\ProtocolException;

/**
 * Encodes and decodes the frames of the host protocol: a 4-byte big-endian
 * length, then that many bytes of UTF-8 JSON holding one object with a type.
 *
 * @see Resources/contract/protocol.json
 *
 * @internal
 */
final class FrameCodec
{
    public const MAX_FRAME_BYTES = 16 * 1024 * 1024;

    private string $buffer = '';

    /**
     * @param array<string, mixed> $message
     */
    public static function encode(array $message): string
    {
        try {
            $json = json_encode($message, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProtocolException(\sprintf('Cannot encode the "%s" message: %s.', $message['type'] ?? '?', $e->getMessage()), 0, $e);
        }

        if (\strlen($json) > self::MAX_FRAME_BYTES) {
            throw new ProtocolException(\sprintf('The "%s" message is %d bytes long, more than the %d bytes of a frame.', $message['type'] ?? '?', \strlen($json), self::MAX_FRAME_BYTES));
        }

        return pack('N', \strlen($json)).$json;
    }

    /**
     * Adds received bytes and returns the messages they complete.
     *
     * @return list<array<string, mixed>>
     */
    public function decode(string $bytes): array
    {
        $this->buffer .= $bytes;
        $messages = [];

        $offset = 0;
        $available = \strlen($this->buffer);
        try {
            while ($available - $offset >= 4) {
                $length = (unpack('N', $this->buffer, $offset) ?: [1 => 0])[1];
                // On 32-bit platforms, unpack() reads a length of 2 GiB or more as a negative integer
                if ($length < 0 || $length > self::MAX_FRAME_BYTES) {
                    throw new ProtocolException(\sprintf('A frame of %d bytes is longer than the %d bytes allowed.', $length, self::MAX_FRAME_BYTES));
                }
                if ($available - $offset < 4 + $length) {
                    break;
                }

                $json = substr($this->buffer, $offset + 4, $length);
                $offset += 4 + $length;

                try {
                    $message = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new ProtocolException(\sprintf('A frame does not hold valid JSON: %s.', $e->getMessage()), 0, $e);
                }

                if (!\is_array($message) || array_is_list($message) && [] !== $message || !\is_string($message['type'] ?? null)) {
                    throw new ProtocolException('A frame does not hold a message object with a type.');
                }

                $messages[] = $message;
            }
        } finally {
            if (0 !== $offset) {
                $this->buffer = substr($this->buffer, $offset);
            }
        }

        return $messages;
    }

    public function hasPendingBytes(): bool
    {
        return '' !== $this->buffer;
    }
}
