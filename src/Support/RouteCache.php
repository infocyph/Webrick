<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RouteCache
{
    public static function build(array $options): string
    {
        $logger = $options['logger'] ?? new NullLogger();
        \assert($logger instanceof LoggerInterface);

        $cachePath = (string)($options['cache'] ?? '');
        if ($cachePath === '') {
            throw new \InvalidArgumentException("RouteCache::build: 'cache' path is required.");
        }

        $matcherOpt = $options['matcher'] ?? null;
        $matcherOpt = $matcherOpt ? \strtolower((string)$matcherOpt) : null;
        $mode = match ($matcherOpt) {
            'fused' => 'fused',
            'sharded' => 'sharded',
            'generated' => 'generated',
            default => \str_ends_with($cachePath, '.php') ? 'fused' : 'sharded',
        };

        $matcher = match ($mode) {
            'generated' => GeneratedMatcher::make(),
            'fused' => FusedMatcher::make(),
            default => ShardedMatcher::make(),
        };
        $routeCache = ($mode === 'sharded') ? \rtrim($cachePath, "/\\") : $cachePath;

        $userRegister = $options['register'] ?? null;
        $routesFile = (string)($options['routes'] ?? '');
        if ($userRegister && !$userRegister instanceof \Closure && !\is_callable($userRegister)) {
            throw new \InvalidArgumentException("RouteCache::build: 'register' must be callable.");
        }
        if (!$userRegister && $routesFile === '') {
            throw new \InvalidArgumentException("RouteCache::build: provide 'register' callable or 'routes' file.");
        }

        /** @var array<string,string> $attributeDirs */
        $attributeDirs = (array)($options['attributeDirs'] ?? []);
        $attributeDirs = self::normalizeAttributeDirs($attributeDirs, getcwd() ?: __DIR__, $logger);

        /** @var string[] $attributeClasses */
        $attributeClasses = array_values(array_filter(array_map('trim', (array)($options['attributeClasses'] ?? []))));

        // Figure out a stable base dir (so relative includes & scans act like runtime)
        $baseDir = getcwd() ?: __DIR__;
        if ($routesFile !== '') {
            $absRoutes = \realpath($routesFile) ?: $routesFile;
            $dir = \is_file($absRoutes) ? \dirname($absRoutes) : null;
            if ($dir) {
                $baseDir = $dir;
            }
        }

        $register = static function (Registrar $r) use (
            $userRegister,
            $routesFile,
            $attributeDirs,
            $attributeClasses,
            $logger,
            $baseDir,
        ): void {
            $cwd = getcwd();
            // Temporarily switch to the routes base directory
            if ($baseDir && @\chdir($baseDir) === false) {
                $logger->warning('[routecache] failed to chdir to baseDir; continuing', ['baseDir' => $baseDir]);
            }

            try {
                if ($userRegister) {
                    ($userRegister)($r);
                } else {
                    /** @psalm-suppress UnresolvableInclude */
                    require $routesFile;
                }

                // Attribute discovery (same CWD as runtime now)
                if ($attributeDirs) {
                    AttributeRouteLoader::registerFromDirs($r, $attributeDirs);
                }
                if ($attributeClasses) {
                    AttributeRouteLoader::register($r, $attributeClasses);
                }
            } finally {
                // Restore original CWD
                if ($cwd !== false) {
                    @\chdir($cwd);
                }
            }
        };

        $signKey = $options['signKey'] ?? null;
        $signedDefaultTtl = (int)($options['signedDefaultTtl'] ?? 900);
        $regOpts = (array)($options['registrarOptions'] ?? []);
        $preGlobal = (array)($options['preGlobal'] ?? []);
        $postGlobal = (array)($options['postGlobal'] ?? []);
        $fallbackAliases = (bool)($options['fallbackAliasesFromRegistrar'] ?? true);

        /** @var null|callable(Collection):void $bind */
        $bind = $options['bindUrlServices'] ?? null;
        if (!$bind) {
            $bind = static function (Collection $routes) use ($signKey, $signedDefaultTtl): void {
                Response::bindUrlServices($routes, $signKey, $signedDefaultTtl);
            };
        }

        RouterKernel::bootWithRegistrar(
            log: $logger,
            matcher: $matcher,
            register: $register,
            routeCache: $routeCache,
            registrarOptions: $regOpts + [
                'exposeUrlServices' => false, // we'll bind explicitly
                'autoSlashRedirect' => (bool)($regOpts['autoSlashRedirect'] ?? false),
                'signKey' => $signKey,
                'signedDefaultTtl' => $signedDefaultTtl,
            ],
            preGlobal: $preGlobal,
            postGlobal: $postGlobal,
            bindUrlServices: $bind,
            fallbackAliasesFromRegistrar: $fallbackAliases,
        );

        return ($mode === 'sharded') ? $routeCache . DIRECTORY_SEPARATOR . '__root.php' : $routeCache;
    }

    public static function clear(array $options): bool
    {
        $cachePath = (string)($options['cache'] ?? '');
        if ($cachePath === '') {
            throw new \InvalidArgumentException("RouteCache::clear: 'cache' path is required.");
        }
        $matcherOpt = $options['matcher'] ?? null;
        $matcherOpt = $matcherOpt ? \strtolower((string)$matcherOpt) : null;
        $mode = match ($matcherOpt) {
            'fused' => 'fused',
            'sharded' => 'sharded',
            'generated' => 'generated',
            default => \str_ends_with($cachePath, '.php') ? 'fused' : 'sharded',
        };

        $aggressive = (bool)($options['aggressive'] ?? false);

        $danger = ['/', '\\', '.', '..', ''];
        if (\in_array($cachePath, $danger, true)) {
            throw new \RuntimeException("RouteCache::clear: refusing to operate on risky path '{$cachePath}'.");
        }

        if ($mode !== 'sharded') {
            return self::rmFile($cachePath);
        }

        $dir = \rtrim($cachePath, "/\\");
        if (!\is_dir($dir)) {
            return false;
        }

        if ($aggressive) {
            return self::rrmdir($dir);
        }

        $removed = false;
        foreach (['__root.php', '__aliases.php'] as $known) {
            $removed = self::rmFile($dir . DIRECTORY_SEPARATOR . $known) || $removed;
        }
        foreach (\glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $php) {
            $base = \basename($php);
            if ($base === '__root.php' || $base === '__aliases.php') {
                continue;
            }
            $removed = self::rmFile($php) || $removed;
        }
        return $removed;
    }

    /** @param array<string,string> $dirs */
    private static function normalizeAttributeDirs(array $dirs, string $cwd, LoggerInterface $log): array
    {
        $out = [];
        foreach ($dirs as $ns => $dir) {
            $ns = rtrim($ns, '\\') . '\\';
            $p = $dir;

            // make absolute if needed
            if (!preg_match('#^([/\\\\]|[A-Za-z]:[/\\\\])#', $p)) {
                $p = $cwd . DIRECTORY_SEPARATOR . ltrim($p, '/\\');
            }
            $rp = \realpath($p) ?: $p;

            if (!\is_dir($rp)) {
                $log->warning('[routecache] attribute dir not found', ['ns' => $ns, 'dir' => $rp]);
                continue;
            }
            $out[$ns] = $rp;
        }
        return $out;
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
