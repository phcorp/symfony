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

use Symfony\Component\TuiApp\Build\BuildTarget;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Exception\ExceptionInterface;

/**
 * Hands the manifest and the payload of a target to the packaging script of its system.
 *
 * @internal
 */
interface PackagerInterface
{
    /**
     * @return list<string> The packages written
     *
     * @throws ExceptionInterface
     */
    public function package(AppConfig $config, BuildTarget $target): array;
}
