<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\TuiApp\Build\Runtime;

use Symfony\Component\TuiApp\Build\BuildTarget;
use Symfony\Component\TuiApp\Config\AppConfig;
use Symfony\Component\TuiApp\Exception\ExceptionInterface;

/**
 * What runs the application: adds it to the payload of a target.
 *
 * @internal
 */
interface RuntimeInterface
{
    /**
     * Checks what the runtime needs, before anything is built.
     *
     * @return list<string> Warnings
     *
     * @throws ExceptionInterface When something the runtime needs is missing
     */
    public function check(AppConfig $config): array;

    /**
     * Adds the runtime to the payload of a target, once the application is copied there.
     *
     * @throws ExceptionInterface
     */
    public function prepare(AppConfig $config, BuildTarget $target): void;
}
