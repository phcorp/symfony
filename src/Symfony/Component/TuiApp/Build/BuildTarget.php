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

use Symfony\Component\TuiApp\Config\AppConfig;

/**
 * A target being built, and where its build goes.
 *
 * @internal
 */
final class BuildTarget
{
    public readonly string $os;
    public readonly string $arch;
    public readonly string $payloadDir;
    public readonly string $manifest;

    /**
     * @param string       $name      Like macos-arm64
     * @param list<string> $prefix    The command prefix that runs the builds of the target: none on its own system, a Linux runner elsewhere
     * @param string       $hostsDir  The bundled native host sources
     * @param string       $workDir   Where the payload and the manifest are prepared
     * @param string       $outputDir Where the packages are written
     */
    public function __construct(
        public readonly string $name,
        public readonly array $prefix,
        public readonly string $hostsDir,
        public readonly string $workDir,
        public readonly string $outputDir,
    ) {
        [$this->os, $this->arch] = explode('-', $name, 2);
        $this->payloadDir = $workDir.'/payload';
        $this->manifest = $workDir.'/app.manifest.json';
    }

    public function isCurrent(): bool
    {
        return $this->name === AppConfig::currentTarget();
    }
}
