<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Constants\MatcherModeEnum;
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router;
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

        $mode = MatcherModeEnum::fromInput(
            isset($options['matcher']) ? (string)$options['matcher'] : null,
            $cachePath,
        );

        $matcher = match ($mode) {
            MatcherModeEnum::GENERATED => GeneratedMatcher::make(),
            MatcherModeEnum::FUSED => FusedMatcher::make(),
            default => ShardedMatcher::make(),
        };
        if (\method_exists($matcher, 'enableCacheWrite')) {
            $matcher->enableCacheWrite(true);
        }
        $routeCache = ($mode === MatcherModeEnum::SHARDED) ? \rtrim($cachePath, "/\\") : $cachePath;

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
                Router::bindUrlServices($routes, $signKey, $signedDefaultTtl);
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

        return ($mode === MatcherModeEnum::SHARDED) ? $routeCache . DIRECTORY_SEPARATOR . '__root.php' : $routeCache;
    }

    public static function clear(array $options): bool
    {
        $cachePath = (string)($options['cache'] ?? '');
        if ($cachePath === '') {
            throw new \InvalidArgumentException("RouteCache::clear: 'cache' path is required.");
        }
        $mode = MatcherModeEnum::fromInput(
            isset($options['matcher']) ? (string)$options['matcher'] : null,
            $cachePath,
        );

        $aggressive = (bool)($options['aggressive'] ?? false);

        $danger = ['/', '\\', '.', '..', ''];
        if (\in_array($cachePath, $danger, true)) {
            throw new \RuntimeException("RouteCache::clear: refusing to operate on risky path '{$cachePath}'.");
        }

        if ($mode !== MatcherModeEnum::SHARDED) {
            return self::rmFile($cachePath);
        }

        $dir = \rtrim($cachePath, "/\\");
        if (!\is_dir($dir)) {
            return false;
        }

        if ($aggressive) {
            return self::clearDirPreservingGitignore($dir);
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

    private static function clearDirPreservingGitignore(string $dir): bool
    {
        if (!\is_dir($dir)) {
            return false;
        }

        $removed = false;
        $ok = true;
        $root = \str_replace('\\', '/', \rtrim($dir, "/\\"));

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($it as $path) {
            $pathname = $path->getPathname();

            if ($path->isDir()) {
                if (@\rmdir($pathname)) {
                    $removed = true;
                } else {
                    $ok = false;
                }
                continue;
            }

            $normalizedPath = \str_replace('\\', '/', $pathname);
            $isRootGitignore = \basename($normalizedPath) === '.gitignore'
                && \dirname($normalizedPath) === $root;
            if ($isRootGitignore) {
                continue;
            }

            if (@\unlink($pathname)) {
                $removed = true;
            } else {
                $ok = false;
            }
        }

        return $ok && $removed;
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
}
