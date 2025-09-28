<?php

/**
 * Webrick - Router cache builder and clearer.
 *
 * @package Infocyph\Webrick\Support
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RouteCache
{
    /* =========================================================================
     * Public API
     * ========================================================================= */

    /**
     * Build/warm the router cache.
     *
     * Options:
     *  - matcher: 'sharded'|'fused'|null (auto)
     *  - cache: string  (dir for sharded; file for fused). REQUIRED
     *  - register: callable(Registrar $r): void  (OR 'routes' => 'path/to/routes.php')
     *  - routes: string (routes.php path if you don’t pass 'register')
     *  - signKey: ?string
     *  - signedDefaultTtl: int (default 900)
     *  - registrarOptions: array
     *  - preGlobal: array<class-string>
     *  - postGlobal: array<class-string>
     *  - bindUrlServices: callable(Collection $routes): void
     *  - logger: LoggerInterface (default NullLogger)
     *  - fallbackAliasesFromRegistrar: bool (default true)
     *  - attributeDirs: array<string,string> (optional)  // ['App\\Http\\Controllers\\' => 'app/Http/Controllers', ...]
     *  - attributeClasses: string[] (optional)           // ['App\\Foo\\BarController', ...]
     */
    public static function build(array $options): string
    {
        $logger    = self::getLogger($options);
        $cachePath = self::requireCachePath($options);

        $isFused   = self::determineMatcher($options, $cachePath);

        [$userRegister, $routesFile]     = self::extractRegistration($options);
        [$attrDirs, $attrClasses]        = self::extractAttributeInputs($options);
        $baseDirForAttr                  = self::baseDirForAttr($routesFile, $userRegister);
        $register                        = self::composeRegistrar($userRegister, $routesFile, $attrDirs, $attrClasses, $baseDirForAttr);

        $signKey          = $options['signKey'] ?? null;
        $signedDefaultTtl = (int)($options['signedDefaultTtl'] ?? 900);
        $regOpts          = (array)($options['registrarOptions'] ?? []);
        $preGlobal        = (array)($options['preGlobal'] ?? []);
        $postGlobal       = (array)($options['postGlobal'] ?? []);
        $fallbackAliases  = (bool)($options['fallbackAliasesFromRegistrar'] ?? true);
        $bind             = self::getBind($options, $signKey, $signedDefaultTtl);

        $matcher    = $isFused ? FusedMatcher::make() : ShardedMatcher::make();
        $routeCache = self::normalizeCacheTarget($isFused, $cachePath);

        self::bootKernel(
            logger: $logger,
            matcher: $matcher,
            register: $register,
            routeCache: $routeCache,
            regOpts: $regOpts + [
                'exposeUrlServices' => true,
                'signKey'           => $signKey,
                'signedDefaultTtl'  => $signedDefaultTtl,
            ],
            preGlobal: $preGlobal,
            postGlobal: $postGlobal,
            bind: $bind,
            fallbackAliases: $fallbackAliases
        );

        return $isFused
            ? $routeCache
            : $routeCache . DIRECTORY_SEPARATOR . '__root.php';
    }

    /**
     * Clear the router cache for fused or sharded modes.
     */
    public static function clear(array $options): bool
    {
        $cachePath = self::requireCachePath($options);
        $isFused   = self::determineMatcher($options, $cachePath);
        self::guardDangerousPath($cachePath);

        return $isFused
            ? self::clearFused($cachePath)
            : self::clearSharded($cachePath, (bool)($options['aggressive'] ?? false));
    }

    private static function baseDirForAttr(string $routesFile, ?callable $userRegister): string
    {
        // Prefer the directory of routes.php when provided; fallback to CWD for custom registrars.
        return $routesFile !== '' ? \dirname($routesFile) : (\getcwd() ?: __DIR__);
    }

    private static function bootKernel(
        LoggerInterface $logger,
        object $matcher,
        callable $register,
        string $routeCache,
        array $regOpts,
        array $preGlobal,
        array $postGlobal,
        callable $bind,
        bool $fallbackAliases
    ): void {
        RouterKernel::bootWithRegistrar(
            log: $logger,
            matcher: $matcher,
            register: $register,
            routeCache: $routeCache,
            registrarOptions: $regOpts,
            preGlobal: $preGlobal,
            postGlobal: $postGlobal,
            bindUrlServices: $bind,
            fallbackAliasesFromRegistrar: $fallbackAliases,
        );
    }

    private static function clearFused(string $file): bool
    {
        return self::rmFile($file);
    }

    private static function clearSharded(string $cachePath, bool $aggressive): bool
    {
        $dir = \rtrim($cachePath, "/\\");
        if (!\is_dir($dir)) {
            return false;
        }

        return $aggressive
            ? self::rrmdir($dir)
            : self::removeCacheArtifacts($dir);
    }

    private static function composeRegistrar(
        ?callable $userRegister,
        string $routesFile,
        array $attrDirs,
        array $attrClasses,
        string $baseDirForAttr
    ): callable {
        $normalizedAttrDirs = self::normalizeAttributeDirs($baseDirForAttr, $attrDirs);

        return static function (Registrar $r) use ($userRegister, $routesFile, $normalizedAttrDirs, $attrClasses): void {
            if ($userRegister) {
                ($userRegister)($r);
            } else {
                require $routesFile;
            }

            if ($normalizedAttrDirs !== []) {
                AttributeRouteLoader::registerFromDirs($r, $normalizedAttrDirs);
            }
            if ($attrClasses !== []) {
                AttributeRouteLoader::register($r, $attrClasses);
            }
        };
    }

    private static function determineMatcher(array $options, string $cachePath): bool
    {
        $matcherOpt = $options['matcher'] ?? null;
        $matcherOpt = $matcherOpt ? \strtolower((string)$matcherOpt) : null;

        // return bool: true => fused, false => sharded
        return match ($matcherOpt) {
            'fused'   => true,
            'sharded' => false,
            default   => \str_ends_with($cachePath, '.php'),
        };
    }

    /**
     * @return array{0:array<string,string>,1:string[]}
     */
    private static function extractAttributeInputs(array $options): array
    {
        /** @var array<string,string> $dirs */
        $dirs = (array)($options['attributeDirs'] ?? []);
        /** @var string[] $classes */
        $classes = (array)($options['attributeClasses'] ?? []);
        return [$dirs, $classes];
    }

    /**
     * @return array{0:?callable,1:string} [$userRegister, $routesFile]
     */
    private static function extractRegistration(array $options): array
    {
        $userRegister = $options['register'] ?? null;
        $routesFile   = (string)($options['routes'] ?? '');

        if ($userRegister && !$userRegister instanceof \Closure && !\is_callable($userRegister)) {
            throw new \InvalidArgumentException("RouteCache::build: 'register' must be callable.");
        }
        if (!$userRegister && $routesFile === '') {
            throw new \InvalidArgumentException("RouteCache::build: provide 'register' callable or 'routes' file.");
        }
        return [$userRegister, $routesFile];
    }

    private static function getBind(array $options, ?string $signKey, int $signedDefaultTtl): callable
    {
        /** @var null|callable $bind */
        $bind = $options['bindUrlServices'] ?? null;

        if ($bind) {
            return $bind;
        }

        return static function (Collection $routes) use ($signKey, $signedDefaultTtl): void {
            Response::bindUrlServices($routes, $signKey, $signedDefaultTtl);
        };
    }

    /* =========================================================================
     * Orchestration helpers (build)
     * ========================================================================= */

    private static function getLogger(array $options): LoggerInterface
    {
        $logger = $options['logger'] ?? new NullLogger();
        \assert($logger instanceof LoggerInterface);
        return $logger;
    }

    /* =========================================================================
     * Orchestration helpers (clear)
     * ========================================================================= */

    private static function guardDangerousPath(string $cachePath): void
    {
        $danger = ['/', '\\', '.', '..', ''];
        if (\in_array($cachePath, $danger, true)) {
            throw new \RuntimeException("RouteCache::clear: refusing to operate on risky path '{$cachePath}'.");
        }
    }

    /* =========================================================================
     * Utilities
     * ========================================================================= */

    /**
     * Resolve Namespace => dir map to absolute dirs (relative to $baseDir).
     *
     * @param string $baseDir
     * @param array<string,string> $map
     * @return array<string,string>
     */
    private static function normalizeAttributeDirs(string $baseDir, array $map): array
    {
        $out = [];
        foreach ($map as $ns => $dir) {
            $ns  = (string)$ns;
            $dir = (string)$dir;

            if ($ns === '' || $dir === '') {
                continue;
            }
            if (!\str_ends_with($ns, '\\')) {
                $ns .= '\\';
            }

            $isAbs = \str_starts_with($dir, DIRECTORY_SEPARATOR)
                || (bool)\preg_match('#^[A-Za-z]:\\\\#', $dir);

            $abs = $isAbs ? $dir : $baseDir . DIRECTORY_SEPARATOR . $dir;
            $out[$ns] = $abs;
        }
        return $out;
    }

    private static function normalizeCacheTarget(bool $isFused, string $cachePath): string
    {
        return $isFused ? $cachePath : \rtrim($cachePath, "/\\");
    }

    private static function removeCacheArtifacts(string $dir): bool
    {
        $removed = false;

        // Known sentinels
        foreach (['__root.php', '__aliases.php'] as $known) {
            $removed = self::rmFile($dir . DIRECTORY_SEPARATOR . $known) || $removed;
        }

        // Shards (*.php)
        foreach (\glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $php) {
            $base = \basename($php);
            if ($base === '__root.php' || $base === '__aliases.php') {
                continue;
            }
            $removed = self::rmFile($php) || $removed;
        }

        return $removed;
    }

    private static function requireCachePath(array $options): string
    {
        $cachePath = (string)($options['cache'] ?? '');
        if ($cachePath === '') {
            throw new \InvalidArgumentException("RouteCache: 'cache' path is required.");
        }
        return $cachePath;
    }

    private static function rmFile(string $file): bool
    {
        return \is_file($file) && @\unlink($file);
    }

    private static function rrmdir(string $dir): bool
    {
        if (!\is_dir($dir)) {
            return false;
        }
        $ok = true;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $path) {
            $ok = ($path->isDir() ? @\rmdir($path->getPathname()) : @\unlink($path->getPathname())) && $ok;
        }
        return @\rmdir($dir) && $ok;
    }
}
