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
    /**
     * @param array<string, mixed> $options
     */
    public static function build(array $options): string
    {
        $logger = self::resolveBuildLogger($options);
        $cachePath = self::resolveBuildCachePath($options);
        [$mode, $matcher, $routeCache] = self::resolveBuildMatcher($options, $cachePath);

        $userRegister = $options['register'] ?? null;
        $routesFile = self::stringOption($options, 'routes');
        self::validateBuildRegisterInputs($userRegister, $routesFile);

        $attributeDirs = self::normalizeAttributeDirs(
            self::stringMapOption($options, 'attributeDirs'),
            getcwd() ?: __DIR__,
            $logger,
        );
        $attributeClasses = self::classListOption($options, 'attributeClasses');

        $baseDir = self::resolveBuildBaseDir($routesFile);
        $register = self::makeBuildRegisterClosure(
            $userRegister,
            $routesFile,
            $attributeDirs,
            $attributeClasses,
            $logger,
            $baseDir,
        );

        $signKey = self::nullableStringOption($options, 'signKey');
        $signedDefaultTtl = self::intOption($options, 'signedDefaultTtl', 900);
        $regOpts = self::assocArrayOption($options, 'registrarOptions');
        $preGlobal = self::listOption($options, 'preGlobal');
        $postGlobal = self::listOption($options, 'postGlobal');
        $fallbackAliases = (bool) ($options['fallbackAliasesFromRegistrar'] ?? true);
        $bind = self::resolveBindUrlServices($options, $signKey, $signedDefaultTtl);

        RouterKernel::bootWithRegistrar(
            log: $logger,
            matcher: $matcher,
            register: $register,
            routeCache: $routeCache,
            registrarOptions: $regOpts + [
                'exposeUrlServices' => false, // we'll bind explicitly
                'autoSlashRedirect' => (bool) ($regOpts['autoSlashRedirect'] ?? false),
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

    /**
     * @param array<string, mixed> $options
     */
    public static function clear(array $options): bool
    {
        $cachePath = self::stringOption($options, 'cache');
        if ($cachePath === '') {
            throw new \InvalidArgumentException("RouteCache::clear: 'cache' path is required.");
        }
        $mode = MatcherModeEnum::fromInput(
            self::nullableStringOption($options, 'matcher'),
            $cachePath,
        );

        $aggressive = (bool) ($options['aggressive'] ?? false);

        $danger = ['/', '\\', '.', '..', ''];
        if (\in_array($cachePath, $danger, true)) {
            throw new \RuntimeException("RouteCache::clear: refusing to operate on risky path '{$cachePath}'.");
        }

        if ($mode !== MatcherModeEnum::SHARDED) {
            return self::rmFile($cachePath);
        }

        $dir = \rtrim($cachePath, '/\\');
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

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function assocArrayOption(array $options, string $key): array
    {
        $value = $options[$key] ?? [];
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $mapKey => $mapValue) {
            if (!\is_string($mapKey)) {
                continue;
            }
            $out[$mapKey] = $mapValue;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<class-string>
     */
    private static function classListOption(array $options, string $key): array
    {
        $value = $options[$key] ?? [];
        if (!\is_array($value)) {
            return [];
        }

        $classes = [];
        foreach ($value as $className) {
            if (!\is_string($className)) {
                continue;
            }
            $className = \trim($className);
            if ($className === '') {
                continue;
            }
            /** @var class-string $className */
            $classes[] = $className;
        }

        return $classes;
    }

    private static function clearDirPreservingGitignore(string $dir): bool
    {
        if (!\is_dir($dir)) {
            return false;
        }

        $removed = false;
        $ok = true;
        $root = \str_replace('\\', '/', \rtrim($dir, '/\\'));

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($it as $path) {
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

    /**
     * @return array{0: bool, 1: bool}
     */
    private static function clearEntry(\SplFileInfo $path, string $root): array
    {
        $pathname = $path->getPathname();
        if ($path->isDir()) {
            $deletedDir = self::removeDirectory($pathname);

            return [$deletedDir, $deletedDir];
        }
        if (self::isRootGitignore($pathname, $root)) {
            return [true, false];
        }

        $deleted = \unlink($pathname);

        return [$deleted, $deleted];
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function intOption(array $options, string $key, int $default): int
    {
        $value = $options[$key] ?? null;
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && $value !== '' && \is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private static function isRootGitignore(string $path, string $root): bool
    {
        $normalizedPath = \str_replace('\\', '/', $path);

        return \basename($normalizedPath) === '.gitignore'
            && \dirname($normalizedPath) === $root;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<mixed>
     */
    private static function listOption(array $options, string $key): array
    {
        $value = $options[$key] ?? [];
        if (!\is_array($value)) {
            return [];
        }

        return \array_values($value);
    }

    /**
     * @param array<string, string> $attributeDirs
     * @param list<class-string> $attributeClasses
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
            if ($baseDir !== '' && \chdir($baseDir) === false) {
                $logger->warning('[routecache] failed to chdir to baseDir; continuing', ['baseDir' => $baseDir]);
            }

            try {
                if ($userRegister) {
                    if (!\is_callable($userRegister)) {
                        throw new \InvalidArgumentException("RouteCache::build: 'register' must be callable.");
                    }
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
                    \chdir($cwd);
                }
            }
        };
    }

    /**
     * @param array<string,string> $dirs
     * @return array<string, string>
     */
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
     * @param array<string, mixed> $options
     */
    private static function nullableStringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;
        if (\is_string($value)) {
            return $value;
        }

        return null;
    }

    private static function removeDirectory(string $path): bool
    {
        return \rmdir($path);
    }

    /**
     * @param array<string, mixed> $options
     * @return \Closure(Collection):void
     */
    private static function resolveBindUrlServices(array $options, ?string $signKey, int $signedDefaultTtl): \Closure
    {
        /** @var null|callable(Collection):void $bind */
        $bind = $options['bindUrlServices'] ?? null;
        if ($bind !== null) {
            return static function (Collection $routes) use ($bind): void {
                $bind($routes);
            };
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

    /**
     * @param array<string, mixed> $options
     */
    private static function resolveBuildCachePath(array $options): string
    {
        $cachePath = self::stringOption($options, 'cache');
        if ($cachePath === '') {
            throw new \InvalidArgumentException("RouteCache::build: 'cache' path is required.");
        }

        return $cachePath;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function resolveBuildLogger(array $options): LoggerInterface
    {
        $logger = $options['logger'] ?? null;
        if ($logger instanceof LoggerInterface) {
            return $logger;
        }

        return new NullLogger();
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0:MatcherModeEnum,1:FusedMatcher|GeneratedMatcher|ShardedMatcher,2:string}
     */
    private static function resolveBuildMatcher(array $options, string $cachePath): array
    {
        $mode = MatcherModeEnum::fromInput(
            self::nullableStringOption($options, 'matcher'),
            $cachePath,
        );
        $matcher = match ($mode) {
            MatcherModeEnum::GENERATED => GeneratedMatcher::make(),
            MatcherModeEnum::FUSED => FusedMatcher::make(),
            default => ShardedMatcher::make(),
        };
        $matcher->enableCacheWrite(true);

        $routeCache = ($mode === MatcherModeEnum::SHARDED) ? \rtrim($cachePath, '/\\') : $cachePath;

        return [$mode, $matcher, $routeCache];
    }

    private static function rmFile(string $file): bool
    {
        return \is_file($file) && \unlink($file);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private static function stringMapOption(array $options, string $key): array
    {
        $value = $options[$key] ?? [];
        if (!\is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $mapKey => $mapValue) {
            if (!\is_string($mapKey) || !\is_string($mapValue)) {
                continue;
            }
            $map[$mapKey] = $mapValue;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function stringOption(array $options, string $key): string
    {
        $value = $options[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
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
