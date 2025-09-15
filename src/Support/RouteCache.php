<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;

final class RouteCache
{
    /**
     * Build/warm the router cache.
     *
     * Options:
     *  - matcher: 'sharded'|'fused'|null (auto-detect by cache path if null)
     *  - cache: string  (dir for sharded; file for fused). REQUIRED
     *  - register: callable(Registrar $r): void  (OR 'routes' => 'path/to/routes.php')
     *  - routes: string (routes.php path if you don’t pass 'register')
     *  - signKey: ?string (for signed URLs; optional)
     *  - signedDefaultTtl: int (default 900 seconds)
     *  - registrarOptions: array (passed to registrar)
     *  - preGlobal: array<class-string>  (optional)
     *  - postGlobal: array<class-string> (optional)
     *  - bindUrlServices: callable(Collection $routes): void (optional; if null, a default binder is used)
     *  - logger: LoggerInterface (default NullLogger)
     *  - fallbackAliasesFromRegistrar: bool (default true)
     *
     * Returns the sentinel path that proves the cache is hot:
     *  - sharded: <cacheDir>/__root.php
     *  - fused:   <cacheFile>
     */
    public static function build(array $options): string
    {
        $logger     = $options['logger'] ?? new NullLogger();
        \assert($logger instanceof LoggerInterface);

        $cachePath  = (string) ($options['cache'] ?? '');
        if ($cachePath === '') {
            throw new \InvalidArgumentException("RouteCache::build: 'cache' path is required.");
        }

        $matcherOpt = $options['matcher'] ?? null;
        $matcherOpt = $matcherOpt ? \strtolower((string) $matcherOpt) : null;

        // Heuristic: if path ends with ".php" treat as fused, otherwise sharded.
        $isFused = match ($matcherOpt) {
            'fused'   => true,
            'sharded' => false,
            default   => \str_ends_with($cachePath, '.php'),
        };

        $register = $options['register'] ?? null;
        if (! $register) {
            $routesFile = (string) ($options['routes'] ?? '');
            if ($routesFile === '') {
                throw new \InvalidArgumentException("RouteCache::build: provide 'register' callable or 'routes' file.");
            }
            $register = static function (Registrar $r) use ($routesFile): void {
                require $routesFile;
            };
        } elseif (! $register instanceof \Closure && ! \is_callable($register)) {
            throw new \InvalidArgumentException("RouteCache::build: 'register' must be callable.");
        }

        $signKey          = $options['signKey']          ?? null;
        $signedDefaultTtl = (int) ($options['signedDefaultTtl'] ?? 900);
        $regOpts          = (array) ($options['registrarOptions'] ?? []);
        $preGlobal        = (array) ($options['preGlobal'] ?? []);
        $postGlobal       = (array) ($options['postGlobal'] ?? []);
        $fallbackAliases  = (bool)  ($options['fallbackAliasesFromRegistrar'] ?? true);

        /** @var null|callable $bind */
        $bind = $options['bindUrlServices'] ?? null;
        if (! $bind) {
            $bind = static function (Collection $routes) use ($signKey, $signedDefaultTtl): void {
                Response::bindUrlServices($routes, $signKey, $signedDefaultTtl);
            };
        }

        $matcher = $isFused ? FusedMatcher::make() : ShardedMatcher::make();

        // Normalize cache target for matcher flavor
        $routeCache = $isFused
            ? $cachePath
            : \rtrim($cachePath, "/\\");

        RouterKernel::bootWithRegistrar(
            log: $logger,
            matcher: $matcher,
            register: $register,
            routeCache: $routeCache,
            registrarOptions: $regOpts + [
                'exposeUrlServices' => true,
                'signKey'           => $signKey,
                'signedDefaultTtl'  => $signedDefaultTtl,
            ],
            preGlobal: $preGlobal,
            postGlobal: $postGlobal,
            bindUrlServices: $bind,
            fallbackAliasesFromRegistrar: $fallbackAliases,
        );

        return $isFused
            ? $routeCache                                   // fused sentinel is the cache file itself
            : $routeCache . DIRECTORY_SEPARATOR . '__root.php';
    }

    /**
     * Clear the router cache.
     *
     * Options:
     *  - matcher: 'sharded'|'fused'|null (auto-detect by cache path if null)
     *  - cache: string  (dir for sharded; file for fused). REQUIRED
     *  - aggressive: bool (default true) — sharded: remove entire dir; fused: unlink the file
     *
     * Returns true if any file/dir was removed.
     */
    public static function clear(array $options): bool
    {
        $cachePath  = (string) ($options['cache'] ?? '');
        if ($cachePath === '') {
            throw new \InvalidArgumentException("RouteCache::clear: 'cache' path is required.");
        }

        $matcherOpt = $options['matcher'] ?? null;
        $matcherOpt = $matcherOpt ? \strtolower((string) $matcherOpt) : null;

        $isFused = match ($matcherOpt) {
            'fused'   => true,
            'sharded' => false,
            default   => \str_ends_with($cachePath, '.php'),
        };

        // ✅ SAFE BY DEFAULT: keep directory for sharded caches
        $aggressive = (bool) ($options['aggressive'] ?? false);

        // Basic guardrails against dangerous targets
        $danger = ['/', '\\', '.', '..', ''];
        if (\in_array($cachePath, $danger, true)) {
            throw new \RuntimeException("RouteCache::clear: refusing to operate on risky path '{$cachePath}'.");
        }

        if ($isFused) {
            // single cache file
            return self::rmFile($cachePath);
        }

        // sharded = directory of PHP shards + sentinels
        $dir = \rtrim($cachePath, "/\\");
        if (! \is_dir($dir)) {
            return false;
        }

        if ($aggressive) {
            // remove directory recursively (dotfiles included) – use with care
            return self::rrmdir($dir);
        }

        // Non-aggressive: remove only our cache artifacts, keep folder & dotfiles (.gitignore)
        $removed = false;

        // Known sentinels
        foreach (['__root.php', '__aliases.php'] as $known) {
            $removed = self::rmFile($dir . DIRECTORY_SEPARATOR . $known) || $removed;
        }

        // Shards (*.php)
        foreach (\glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $php) {
            $base = \basename($php);
            if ($base === '__root.php' || $base === '__aliases.php') {
                continue; // already handled
            }
            $removed = self::rmFile($php) || $removed;
        }

        // keep the directory (so .gitignore survives)
        return $removed;
    }


    private static function rmFile(string $file): bool
    {
        return \is_file($file) && @\unlink($file);
    }

    private static function rrmdir(string $dir): bool
    {
        if (! \is_dir($dir)) {
            return false;
        }
        $ok = true;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path) {
            $ok = ($path->isDir() ? @\rmdir($path->getPathname()) : @\unlink($path->getPathname())) && $ok;
        }
        return @\rmdir($dir) && $ok;
    }
}
