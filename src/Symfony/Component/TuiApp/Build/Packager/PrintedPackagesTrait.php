<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Build\Packager;

use Symfony\Component\TuiApp\Exception\RuntimeException;

/**
 * @internal
 */
trait PrintedPackagesTrait
{
    /**
     * The packages a script printed, one path per line.
     *
     * @return list<string>
     */
    private static function printedPackages(string $output, string $outputDir): array
    {
        $packages = array_values(array_filter(array_map(trim(...), explode("\n", $output)), static fn (string $line): bool => '' !== $line && file_exists($line)));
        if (!$packages) {
            throw new RuntimeException(\sprintf('The packaging wrote nothing to "%s".', $outputDir));
        }

        return $packages;
    }
}
