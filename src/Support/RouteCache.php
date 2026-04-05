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
        $logger = self::resolveBuildLogger($options);
        $cachePath = self::resolveBuildCachePath($options);
        [$mode, $matcher, $routeCache] = self::resolveBuildMatcher($options, $cachePath);

        $userRegister = $options['register'] ?? null;
        $routesFile = (string)($options['routes'] ?? '');
        self::validateBuildRegisterInputs($userRegister, $routesFile);

        /** @var array<string,string> $attributeDirs */
        $attributeDirs = self::normalizeAttributeDirs(
            (array)($options['attributeDirs'] ?? []),
            getcwd() ?: __DIR__,
            $logger,
        );
        /** @var string[] $attributeClasses */
        $attributeClasses = \array_values(\array_filter(\array_map('trim', (array)($options['attributeClasses'] ?? []))));

        $baseDir = self::resolveBuildBaseDir($routesFile);
        $register = self::makeBuildRegisterClosure(
            $userRegister,
            $routesFile,
            $attributeDirs,
            $attributeClasses,
            $logger,
            $baseDir,
        );

        $signKey = $options['signKey'] ?? null;
        $signedDefaultTtl = (int)($options['signedDefaultTtl'] ?? 900);
        $regOpts = (array)($options['registrarOptions'] ?? []);
        $preGlobal = (array)($options['preGlobal'] ?? []);
        $postGlobal = (array)($options['postGlobal'] ?? []);
        $fallbackAliases = (bool)($options['fallbackAliasesFromRegistrar'] ?? true);
        $bind = self::resolveBindUrlServices($options, $signKey, $signedDefaultTtl);

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

    /**
     * @param array<string,string> $attributeDirs
     * @param string[] $attributeClasses
     */
    private static function makeBuildRegisterClosure(
        mixed $userRegister,
        string $routesFile,
        array $attributeDirs,
        array $attributeClasses,
        LoggerInterface $logger,
        string $baseDir,
    ): \Closure {
        return static function (Registrar $r) use (
            $userRegister,
            $routesFile,
            $attributeDirs,
            $attributeClasses,
            $logger,
            $baseDir,
        ): void {
            $cwd = getcwd();
            if ($baseDir !== '' && @\chdir($baseDir) === false) {
                $logger->warning('[routecache] failed to chdir to baseDir; continuing', ['baseDir' => $baseDir]);
            }

            try {
                if ($userRegister) {
                    ($userRegister)($r);
                } else {
                    /** @psalm-suppress UnresolvableInclude */
                    require $routesFile;
                }

                if ($attributeDirs !== []) {
                    AttributeRouteLoader::registerFromDirs($r, $attributeDirs);
                }
                if ($attributeClasses !== []) {
                    AttributeRouteLoader::register($r, $attributeClasses);
                }
            } finally {
                if ($cwd !== false) {
                    @\chdir($cwd);
                }
            }
        };
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

    /**
     * @return callable(Collection):void
     */
    private static function resolveBindUrlServices(array $options, mixed $signKey, int $signedDefaultTtl): callable
    {
        /** @var null|callable(Collection):void $bind */
        $bind = $options['bindUrlServices'] ?? null;
        if ($bind !== null) {
            return $bind;
        }

        return static function (Collection $routes) use ($signKey, $signedDefaultTtl): void {
            Router::bindUrlServices($routes, $signKey, $signedDefaultTtl);
        };
    }

    private static function resolveBuildBaseDir(string $routesFile): string
    {
        $baseDir = getcwd() ?: __DIR__;
        if ($routesFile === '') {
            return $baseDir;
        }

        $absRoutes = \realpath($routesFile) ?: $routesFile;
        if (\is_file($absRoutes)) {
            return \dirname($absRoutes);
        }

        return $baseDir;
    }

    private static function resolveBuildCachePath(array $options): string
    {
        $cachePath = (string)($options['cache'] ?? '');
        if ($cachePath === '') {
            throw new \InvalidArgumentException("RouteCache::build: 'cache' path is required.");
        }
        return $cachePath;
    }

    private static function resolveBuildLogger(array $options): LoggerInterface
    {
        $logger = $options['logger'] ?? new NullLogger();
        \assert($logger instanceof LoggerInterface);
        return $logger;
    }

    /**
     * @return array{0:MatcherModeEnum,1:FusedMatcher|GeneratedMatcher|ShardedMatcher,2:string}
     */
    private static function resolveBuildMatcher(array $options, string $cachePath): array
    {
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
        return [$mode, $matcher, $routeCache];
    }

    private static function rmFile(string $file): bool
    {
        return \is_file($file) && @\unlink($file);
    }

    private static function validateBuildRegisterInputs(mixed $userRegister, string $routesFile): void
    {
        if ($userRegister && !$userRegister instanceof \Closure && !\is_callable($userRegister)) {
            throw new \InvalidArgumentException("RouteCache::build: 'register' must be callable.");
        }
        if (!$userRegister && $routesFile === '') {
            throw new \InvalidArgumentException("RouteCache::build: provide 'register' callable or 'routes' file.");
        }
    }
}
