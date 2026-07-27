<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Constants\MatcherModeEnum;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
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
        $inputs = self::resolveBuildInputs($options, $logger);

        // A build is an explicit refresh operation. Remove only this matcher's
        // old output so colocated caches for other matcher modes remain valid.
        self::clear([
            'cache' => $cachePath,
            'matcher' => $mode->value,
            'aggressive' => false,
        ]);

        RouterKernel::bootWithRegistrar(
            log: $logger,
            matcher: $matcher,
            register: $inputs['register'],
            routeCache: $routeCache,
            registrarOptions: $inputs['registrarOptions'] + [
                'exposeUrlServices' => false, // we'll bind explicitly
                'autoSlashRedirect' => (bool) ($inputs['registrarOptions']['autoSlashRedirect'] ?? false),
                'signKey' => $inputs['signKey'],
                'signedDefaultTtl' => $inputs['signedDefaultTtl'],
                'signedUrlConfig' => $inputs['signedUrlConfig'],
                'urlBaseUri' => $inputs['urlBaseUri'],
            ],
            preGlobal: $inputs['preGlobal'],
            postGlobal: $inputs['postGlobal'],
            bindUrlServices: $inputs['bind'],
            fallbackAliasesFromRegistrar: $inputs['fallbackAliases'],
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
            if (\in_array($base, ['__root.php', '__aliases.php', '__routes.php', '__generated.php'], true)) {
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

        return [true, self::rmFile($pathname)];
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
        ?string $signKey,
    ): \Closure {
        return \Closure::fromCallable(new RouteCacheBuildRegistrarCallback(
            $userRegister,
            $routesFile,
            $attributeDirs,
            $attributeClasses,
            $logger,
            $baseDir,
            $signKey,
        ));
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
        if (!\is_writable(\dirname($path))) {
            throw new \RuntimeException("Route cache directory is not writable: {$path}");
        }
        if (!\rmdir($path)) {
            throw new \RuntimeException("Unable to remove route cache directory: {$path}");
        }

        return true;
    }

    /**
     * @param array<string, mixed> $options
     * @return \Closure(Collection):void
     */
    private static function resolveBindUrlServices(
        array $options,
        ?string $signKey,
        int $signedDefaultTtl,
        ?SignedUrlConfig $signedUrlConfig,
        string $urlBaseUri,
    ): \Closure {
        /** @var null|callable(Collection):void $bind */
        $bind = $options['bindUrlServices'] ?? null;
        if ($bind !== null) {
            return \Closure::fromCallable($bind);
        }

        return \Closure::fromCallable(new RouteCacheBindUrlServicesCallback(
            $signKey,
            $signedDefaultTtl,
            $signedUrlConfig,
            $urlBaseUri,
        ));
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
     * @return array{
     *   register:\Closure(Registrar):void,
     *   registrarOptions:array<string,mixed>,
     *   signKey:?string,
     *   signedDefaultTtl:int,
     *   signedUrlConfig:?SignedUrlConfig,
     *   urlBaseUri:string,
     *   preGlobal:list<mixed>,
     *   postGlobal:list<mixed>,
     *   fallbackAliases:bool,
     *   bind:\Closure(Collection):void
     * }
     */
    private static function resolveBuildInputs(array $options, LoggerInterface $logger): array
    {
        $userRegister = $options['register'] ?? null;
        $routesFile = self::stringOption($options, 'routes');
        self::validateBuildRegisterInputs($userRegister, $routesFile);

        $attributeDirs = self::normalizeAttributeDirs(
            self::stringMapOption($options, 'attributeDirs'),
            getcwd() ?: __DIR__,
            $logger,
        );
        $attributeClasses = self::classListOption($options, 'attributeClasses');
        $signKey = self::nullableStringOption($options, 'signKey');
        $signedDefaultTtl = self::intOption($options, 'signedDefaultTtl', 900);
        $signedUrlConfig = self::signedUrlConfigOption($options, 'signedUrlConfig');
        $urlBaseUri = self::stringOption($options, 'urlBaseUri');

        return [
            'register' => self::makeBuildRegisterClosure(
                $userRegister,
                $routesFile,
                $attributeDirs,
                $attributeClasses,
                $logger,
                self::resolveBuildBaseDir($routesFile),
                $signKey,
            ),
            'registrarOptions' => self::assocArrayOption($options, 'registrarOptions'),
            'signKey' => $signKey,
            'signedDefaultTtl' => $signedDefaultTtl,
            'signedUrlConfig' => $signedUrlConfig,
            'urlBaseUri' => $urlBaseUri,
            'preGlobal' => self::listOption($options, 'preGlobal'),
            'postGlobal' => self::listOption($options, 'postGlobal'),
            'fallbackAliases' => (bool) ($options['fallbackAliasesFromRegistrar'] ?? true),
            'bind' => self::resolveBindUrlServices(
                $options,
                $signKey,
                $signedDefaultTtl,
                $signedUrlConfig,
                $urlBaseUri,
            ),
        ];
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
        if (!\is_file($file)) {
            return false;
        }
        $directory = \dirname($file);
        if (!\is_writable($directory)) {
            throw new \RuntimeException("Route cache directory is not writable: {$directory}");
        }
        if (!\unlink($file)) {
            throw new \RuntimeException("Unable to remove route cache file: {$file}");
        }

        return true;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function signedUrlConfigOption(array $options, string $key): ?SignedUrlConfig
    {
        $value = $options[$key] ?? null;
        if ($value instanceof SignedUrlConfig) {
            return $value;
        }

        if (\is_array($value) && $value !== []) {
            return SignedUrlConfig::fromArray($value);
        }

        return null;
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
