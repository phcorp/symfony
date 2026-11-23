<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Build;

use Symfony\Component\TuiApp\Exception\InvalidArgumentException;

/**
 * Version constraints like ^0.27, ~1.2.3, ">=0.27 <0.29" or "0.26 || ^0.27":
 * alternatives separated by ||, each made of conditions that must all hold,
 * separated by spaces or commas.
 *
 * @internal
 */
final class VersionConstraint
{
    private const CONDITION = '/^(\^|~|>=|<=|>|<|=)?v?(\d+)(?:\.(\d+))?(?:\.(\d+))?$/D';

    public static function isValid(string $constraint): bool
    {
        return null !== self::parse($constraint);
    }

    /**
     * @throws InvalidArgumentException When the constraint cannot be read
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        $alternatives = self::parse($constraint) ?? throw new InvalidArgumentException(\sprintf('"%s" is not a version constraint, like ^0.27, ~0.27.1 or ">=0.27 <0.29".', $constraint));

        foreach ($alternatives as $conditions) {
            foreach ($conditions as $condition) {
                if (!self::matches($version, ...$condition)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @return list<list<array{string, string, string, string}>>|null
     */
    private static function parse(string $constraint): ?array
    {
        $alternatives = [];
        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $alternative) {
            $conditions = [];
            foreach (preg_split('/[\s,]+/', (string) preg_replace('/(\^|~|>=|<=|>|<|=)\s+/', '$1', $alternative), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $condition) {
                if (!preg_match(self::CONDITION, $condition, $match)) {
                    return null;
                }
                $conditions[] = [$match[1], $match[2], $match[3] ?? '', $match[4] ?? ''];
            }
            if (!$conditions) {
                return null;
            }
            $alternatives[] = $conditions;
        }

        return $alternatives ?: null;
    }

    private static function matches(string $version, string $operator, string $major, string $minor, string $patch): bool
    {
        $base = $major.'.'.('' === $minor ? '0' : $minor).'.'.('' === $patch ? '0' : $patch);

        return match ($operator) {
            '^' => version_compare($version, $base, '>=') && version_compare($version, '0' === $major ? '0.'.((int) $minor + 1).'.0' : ((int) $major + 1).'.0.0', '<'),
            '~' => version_compare($version, $base, '>=') && version_compare($version, '' === $patch ? ((int) $major + 1).'.0.0' : $major.'.'.((int) $minor + 1).'.0', '<'),
            '', '=' => '' === $minor ? (int) explode('.', $version)[0] === (int) $major : ('' === $patch ? str_starts_with($version.'.', $major.'.'.$minor.'.') : version_compare($version, $base, '==')),
            default => version_compare($version, $base, $operator),
        };
    }
}
