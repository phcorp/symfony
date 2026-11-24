<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

for ($directory = __DIR__; dirname($directory) !== $directory; $directory = dirname($directory)) {
    if (is_file($autoload = $directory.'/vendor/autoload.php')) {
        require $autoload;

        return;
    }
}

throw new RuntimeException('Install Composer dependencies before running this demo, or set TUI_APP_AUTOLOAD.');
