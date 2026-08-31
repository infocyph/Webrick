<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

/** Filesystem operations for route-cache cleanup, isolated from build orchestration. */
final class RouteCacheCleaner
{
    public static function clearDirectoryPreservingGitignore(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $removed = false;
        $ok = true;
        $root = str_replace('\\', '/', rtrim($dir, '/\\'));
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $path) {
            if (!$path instanceof \SplFileInfo) {
                $ok = false;

                continue;
            }
            [$entryOk, $entryRemoved] = self::clearEntry($path, $root);
            $ok = $ok && $entryOk;
            $removed = $removed || $entryRemoved;
        }

        return $ok && $removed;
    }

    public static function removeDirectory(string $path): bool
    {
        if (!is_writable(dirname($path))) {
            throw new \RuntimeException("Route cache directory is not writable: {$path}");
        }
        if (!rmdir($path)) {
            throw new \RuntimeException("Unable to remove route cache directory: {$path}");
        }

        return true;
    }

    public static function removeFile(string $file): bool
    {
        if (!is_file($file) && !is_link($file)) {
            return false;
        }
        $directory = dirname($file);
        if (!is_writable($directory)) {
            throw new \RuntimeException("Route cache directory is not writable: {$directory}");
        }
        if (!unlink($file)) {
            throw new \RuntimeException("Unable to remove route cache file: {$file}");
        }

        return true;
    }

    /** @return array{bool,bool} */
    private static function clearEntry(\SplFileInfo $path, string $root): array
    {
        $pathname = $path->getPathname();
        if ($path->isLink()) {
            return [true, self::removeFile($pathname)];
        }
        if ($path->isDir()) {
            $deleted = self::removeDirectory($pathname);

            return [$deleted, $deleted];
        }
        if (basename(str_replace('\\', '/', $pathname)) === '.gitignore' && dirname(str_replace('\\', '/', $pathname)) === $root) {
            return [true, false];
        }

        return [true, self::removeFile($pathname)];
    }
}
