<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Constants\MatcherModeEnum;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Matching\FusedMatcher;
use Infocyph\Webrick\Router\Matching\GeneratedMatcher;
use Infocyph\Webrick\Router\Matching\MatcherInterface;
use Infocyph\Webrick\Router\Matching\ShardedMatcher;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Build-time matcher cache utility. No request kernel or DI runtime is booted. */
final class RouteCache
{
    /** @param array<string,mixed> $options */
    public static function build(array $options): string
    {
        $logger = self::resolveBuildLogger($options);
        $cachePath = self::resolveBuildCachePath($options);
        [$mode, $matcher, $cacheLocation] = self::resolveBuildMatcher($options, $cachePath);
        $inputs = self::resolveBuildInputs($options, $logger);

        $routes = new Collection();
        $registrarOptions = $inputs['registrarOptions'] + [
            'autoSlashRedirect' => false,
            'signKey' => $inputs['signKey'],
            'signedDefaultTtl' => $inputs['signedDefaultTtl'],
            'signedUrlConfig' => $inputs['signedUrlConfig'],
            'urlBaseUri' => $inputs['urlBaseUri'],
        ];
        $signedConfig = $registrarOptions['signedUrlConfig'] ?? null;
        if (is_array($signedConfig) && $signedConfig !== []) {
            $signedConfig = SignedUrlConfig::fromArray($signedConfig);
        }
        if (!$signedConfig instanceof SignedUrlConfig) {
            $signedConfig = null;
        }

        $registrar = new Registrar(
            routes: $routes,
            autoSlashRedirect: (bool) $registrarOptions['autoSlashRedirect'],
            exposeUrlServices: false,
            signKey: self::nullableString($registrarOptions['signKey'] ?? null),
            signedDefaultTtl: self::nullableInt($registrarOptions['signedDefaultTtl'] ?? null),
            signedUrlConfig: $signedConfig,
            urlBaseUri: is_string($registrarOptions['urlBaseUri']) ? $registrarOptions['urlBaseUri'] : '',
        );

        Router::withScopedInstance(
            $registrar,
            static function (Registrar $active) use ($inputs): void {
                ($inputs['register'])($active);
            },
        );

        $compiled = $routes->compile()->all();
        if ($compiled === []) {
            throw new \RuntimeException('Route cache build produced an empty route table.');
        }

        $matcher->enableCache($cacheLocation)->enableCacheWrite(true);
        foreach ($compiled as $route) {
            $matcher->add($route);
        }
        $matcher->finalize();

        $logger->info('[routecache] matcher cache built', [
            'matcher' => $mode->value,
            'routes' => count($compiled),
            'cache' => $cacheLocation,
        ]);

        return $mode === MatcherModeEnum::SHARDED
            ? $cacheLocation . DIRECTORY_SEPARATOR . '__manifest.php'
            : $cacheLocation;
    }

    /** @param array<string,mixed> $options */
    public static function clear(array $options): bool
    {
        $cachePath = self::stringOption($options, 'cache');
        if ($cachePath === '') {
            throw new \InvalidArgumentException("RouteCache::clear: 'cache' path is required.");
        }

        $mode = MatcherModeEnum::fromInput(self::nullableStringOption($options, 'matcher'), $cachePath);
        if (in_array($cachePath, ['/', '\\', '.', '..', ''], true)) {
            throw new \RuntimeException("RouteCache::clear: refusing to operate on risky path '{$cachePath}'.");
        }

        if ($mode !== MatcherModeEnum::SHARDED) {
            return RouteCacheCleaner::removeFile($cachePath);
        }

        return self::clearSharded(rtrim($cachePath, '/\\'), (bool) ($options['aggressive'] ?? false));
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function assocArrayOption(array $options, string $key): array
    {
        $value = $options[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $mapKey => $mapValue) {
            if (is_string($mapKey)) {
                $out[$mapKey] = $mapValue;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $options
     * @return list<class-string>
     */
    private static function classListOption(array $options, string $key): array
    {
        $value = $options[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        $classes = [];
        foreach ($value as $className) {
            if (!is_string($className) || trim($className) === '') {
                continue;
            }
            $className = trim($className);
            if (class_exists($className)) {
                $classes[] = $className;
            }
        }

        return $classes;
    }

    private static function clearSharded(string $dir, bool $aggressive): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        if ($aggressive) {
            return RouteCacheCleaner::clearDirectoryPreservingGitignore($dir);
        }

        $removed = false;
        foreach (['__root.php', '__aliases.php', '__manifest.php', '__current'] as $known) {
            $removed = RouteCacheCleaner::removeFile($dir . DIRECTORY_SEPARATOR . $known) || $removed;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $php) {
            if (in_array(basename($php), ['__root.php', '__aliases.php', '__routes.php', '__generated.php'], true)) {
                continue;
            }
            $removed = RouteCacheCleaner::removeFile($php) || $removed;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'generation-*', GLOB_ONLYDIR) ?: [] as $generation) {
            $generationRemoved = RouteCacheCleaner::clearDirectoryPreservingGitignore($generation);
            if (is_dir($generation)) {
                RouteCacheCleaner::removeDirectory($generation);
                $generationRemoved = true;
            }
            $removed = $generationRemoved || $removed;
        }

        return $removed;
    }

    /** @param array<string,mixed> $options */
    private static function intOption(array $options, string $key, int $default): int
    {
        $value = $options[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && $value !== '' && is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string,string> $attributeDirs
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
     * @return array<string,string>
     */
    private static function normalizeAttributeDirs(array $dirs, string $cwd, LoggerInterface $logger): array
    {
        $out = [];
        foreach ($dirs as $namespace => $dir) {
            $namespace = rtrim($namespace, '\\') . '\\';
            $path = $dir;
            if (!preg_match('#^([/\\\\]|[A-Za-z]:[/\\\\])#', $path)) {
                $path = $cwd . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
            }
            $path = realpath($path) ?: $path;
            if (!is_dir($path)) {
                $logger->warning('[routecache] attribute dir not found', ['ns' => $namespace, 'dir' => $path]);

                continue;
            }
            $out[$namespace] = $path;
        }

        return $out;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && $value !== '' && is_numeric($value) ? (int) $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function nullableStringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private static function resolveBuildBaseDir(string $routesFile): string
    {
        $baseDir = getcwd() ?: __DIR__;
        if ($routesFile === '') {
            return $baseDir;
        }

        $absolute = realpath($routesFile) ?: $routesFile;

        return is_file($absolute) ? dirname($absolute) : $baseDir;
    }

    /** @param array<string,mixed> $options */
    private static function resolveBuildCachePath(array $options): string
    {
        $cachePath = self::stringOption($options, 'cache');
        if ($cachePath === '') {
            throw new \InvalidArgumentException("RouteCache::build: 'cache' path is required.");
        }

        return $cachePath;
    }

    /**
     * @param array<string,mixed> $options
     * @return array{register:\Closure(Registrar):void,registrarOptions:array<string,mixed>,signKey:?string,signedDefaultTtl:int,signedUrlConfig:?SignedUrlConfig,urlBaseUri:string}
     */
    private static function resolveBuildInputs(array $options, LoggerInterface $logger): array
    {
        $userRegister = $options['register'] ?? null;
        $routesFile = self::stringOption($options, 'routes');
        self::validateBuildRegisterInputs($userRegister, $routesFile);

        $signKey = self::nullableStringOption($options, 'signKey');

        return [
            'register' => self::makeBuildRegisterClosure(
                $userRegister,
                $routesFile,
                self::normalizeAttributeDirs(
                    self::stringMapOption($options, 'attributeDirs'),
                    getcwd() ?: __DIR__,
                    $logger,
                ),
                self::classListOption($options, 'attributeClasses'),
                $logger,
                self::resolveBuildBaseDir($routesFile),
                $signKey,
            ),
            'registrarOptions' => self::assocArrayOption($options, 'registrarOptions'),
            'signKey' => $signKey,
            'signedDefaultTtl' => self::intOption($options, 'signedDefaultTtl', 900),
            'signedUrlConfig' => self::signedUrlConfigOption($options, 'signedUrlConfig'),
            'urlBaseUri' => self::stringOption($options, 'urlBaseUri'),
        ];
    }

    /** @param array<string,mixed> $options */
    private static function resolveBuildLogger(array $options): LoggerInterface
    {
        return ($options['logger'] ?? null) instanceof LoggerInterface
            ? $options['logger']
            : new NullLogger();
    }

    /**
     * @param array<string,mixed> $options
     * @return array{0:MatcherModeEnum,1:MatcherInterface,2:string}
     */
    private static function resolveBuildMatcher(array $options, string $cachePath): array
    {
        $mode = MatcherModeEnum::fromInput(self::nullableStringOption($options, 'matcher'), $cachePath);
        $matcher = match ($mode) {
            MatcherModeEnum::GENERATED => GeneratedMatcher::make(),
            MatcherModeEnum::FUSED => FusedMatcher::make(),
            MatcherModeEnum::SHARDED => ShardedMatcher::make(),
        };

        return [
            $mode,
            $matcher,
            $mode === MatcherModeEnum::SHARDED ? rtrim($cachePath, '/\\') : $cachePath,
        ];
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function signedUrlConfigOption(array $options, string $key): ?SignedUrlConfig
    {
        $value = $options[$key] ?? null;
        if ($value instanceof SignedUrlConfig) {
            return $value;
        }

        return is_array($value) && $value !== [] ? SignedUrlConfig::fromArray($value) : null;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,string>
     */
    private static function stringMapOption(array $options, string $key): array
    {
        $value = $options[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $mapKey => $mapValue) {
            if (is_string($mapKey) && is_string($mapValue)) {
                $map[$mapKey] = $mapValue;
            }
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $options
     */
    private static function stringOption(array $options, string $key): string
    {
        $value = $options[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private static function validateBuildRegisterInputs(mixed $userRegister, string $routesFile): void
    {
        if ($userRegister && !$userRegister instanceof \Closure && !is_callable($userRegister)) {
            throw new \InvalidArgumentException("RouteCache::build: 'register' must be callable.");
        }
        if (!$userRegister && $routesFile === '') {
            throw new \InvalidArgumentException("RouteCache::build: provide 'register' callable or 'routes' file.");
        }
    }
}
