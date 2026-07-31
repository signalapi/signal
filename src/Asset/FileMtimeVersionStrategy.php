<?php

namespace App\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;

/**
 * Appends the file's mtime to every asset URL.
 *
 * There is no build step here, so signal.css keeps its name for its whole life
 * and browsers happily serve a months-old copy after a deploy. Keying the URL
 * on mtime means an edited file is a new URL, and an unchanged one still hits
 * the cache.
 */
final class FileMtimeVersionStrategy implements VersionStrategyInterface
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(private readonly string $publicDir)
    {
    }

    public function getVersion(string $path): string
    {
        return $this->cache[$path] ??= $this->mtime($path);
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);

        return '' === $version ? $path : $path . (str_contains($path, '?') ? '&' : '?') . 'v=' . $version;
    }

    private function mtime(string $path): string
    {
        $file = $this->publicDir . '/' . ltrim($path, '/');
        $mtime = is_file($file) ? filemtime($file) : false;

        return false === $mtime ? '' : (string) $mtime;
    }
}
