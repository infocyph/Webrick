<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Matching;

/**
 * Owns immutable sharded-cache generation creation and manifest activation.
 */
final class ShardedCacheGeneration
{
    private const string MANIFEST = '__manifest.php';

    private const string METADATA = '__metadata.php';

    private const string POINTER = '__current';

    /**
     * @return array{0:string,1:string}
     * @param string $cacheDir
     */
    public static function create(string $cacheDir): array
    {
        if (!\is_dir($cacheDir) && !\mkdir($cacheDir, 0775, true) && !\is_dir($cacheDir)) {
            throw new \RuntimeException("Cannot create cache dir {$cacheDir}");
        }

        $generation = 'generation-' . \date('YmdHis') . '-' . \bin2hex(\random_bytes(6));

        return [$generation, $cacheDir . \DIRECTORY_SEPARATOR . $generation];
    }

    /**
     * @return list<string>
     * @param string $cacheDir
     * @param int $version
     */
    public static function middlewareRequirements(string $cacheDir, int $version): array
    {
        $active = self::resolve($cacheDir, $version);
        if ($active === null) {
            return [];
        }
        $metadata = $active . \DIRECTORY_SEPARATOR . self::METADATA;
        if (!\is_file($metadata)) {
            throw new \RuntimeException('Sharded route-cache metadata is missing. Rebuild the route cache.');
        }
        $blob = require $metadata;
        if (!\is_array($blob) || ($blob['_version'] ?? null) !== $version) {
            throw new \RuntimeException('Stale sharded route-cache metadata. Rebuild the route cache.');
        }

        return matcher_normalize_middleware_requirements($blob['_middleware'] ?? []);
    }

    /**
     * @param list<string> $middleware
     * @param string $cacheDir
     * @param int $version
     * @param string $generation
     */
    public static function publish(string $cacheDir, int $version, string $generation, array $middleware): void
    {
        self::writeMetadata($cacheDir, $version, $generation, $middleware);
        $php = "<?php\nreturn [\n"
            . "    '_version' => {$version},\n"
            . "    '_generation' => " . \var_export($generation, true) . ",\n"
            . "    '_middleware' => " . \var_export($middleware, true) . ",\n"
            . "];\n";

        matcher_write_validated_atomic_php_file(
            self::manifestPath($cacheDir),
            $php,
            static function (array $blob) use ($generation, $middleware, $version): void {
                if (($blob['_version'] ?? null) !== $version) {
                    throw new \UnexpectedValueException('Generated sharded manifest has an invalid format version.');
                }
                if (($blob['_generation'] ?? null) !== $generation) {
                    throw new \UnexpectedValueException('Generated sharded manifest has an invalid generation.');
                }
                if (($blob['_middleware'] ?? null) !== $middleware) {
                    throw new \UnexpectedValueException('Generated sharded manifest has invalid middleware metadata.');
                }
            },
        );
        self::publishPointer($cacheDir, $generation);
    }

    public static function resolve(string $cacheDir, int $version): ?string
    {
        $pointed = self::resolvePointer($cacheDir);
        if ($pointed !== null) {
            return $pointed;
        }

        $manifest = self::manifestPath($cacheDir);
        if (!\is_file($manifest)) {
            return null;
        }

        $blob = require $manifest;
        if (!\is_array($blob) || ($blob['_version'] ?? null) !== $version) {
            throw new \RuntimeException('Stale sharded route-cache manifest. Rebuild the route cache.');
        }
        $generation = $blob['_generation'] ?? null;
        if (!\is_string($generation)) {
            throw new \RuntimeException('Invalid sharded route-cache generation identifier.');
        }

        return self::activeDirectory($cacheDir, $generation);
    }

    private static function activeDirectory(string $cacheDir, string $generation): string
    {
        if (\preg_match('/^generation-[A-Za-z0-9-]+$/D', $generation) !== 1) {
            throw new \RuntimeException('Invalid sharded route-cache generation identifier.');
        }

        $active = $cacheDir . \DIRECTORY_SEPARATOR . $generation;
        if (!\is_dir($active)) {
            throw new \RuntimeException('Published sharded route-cache generation is missing.');
        }

        return $active;
    }

    private static function ignoringWarnings(\Closure $operation): mixed
    {
        \set_error_handler(static fn(): bool => true);

        try {
            return $operation();
        } finally {
            \restore_error_handler();
        }
    }

    private static function manifestPath(string $cacheDir): string
    {
        return $cacheDir . \DIRECTORY_SEPARATOR . self::MANIFEST;
    }

    private static function publishPointer(string $cacheDir, string $generation): void
    {
        $temporary = $cacheDir . \DIRECTORY_SEPARATOR . '.current-' . \bin2hex(\random_bytes(6));
        $linked = self::ignoringWarnings(static fn(): bool => \symlink($generation, $temporary));
        if (!$linked) {
            return;
        }

        $published = self::ignoringWarnings(
            static fn(): bool => \rename($temporary, $cacheDir . \DIRECTORY_SEPARATOR . self::POINTER),
        );
        if (!$published) {
            self::ignoringWarnings(static fn(): bool => \unlink($temporary));
        }
    }

    private static function resolvePointer(string $cacheDir): ?string
    {
        $pointer = $cacheDir . \DIRECTORY_SEPARATOR . self::POINTER;
        if (!\is_link($pointer)) {
            return null;
        }
        $generation = \readlink($pointer);
        if (!\is_string($generation)) {
            throw new \RuntimeException('Unable to read the sharded route-cache generation pointer.');
        }

        return self::activeDirectory($cacheDir, $generation);
    }

    /**
     * @param list<string> $middleware
     * @param string $cacheDir
     * @param int $version
     * @param string $generation
     */
    private static function writeMetadata(string $cacheDir, int $version, string $generation, array $middleware): void
    {
        $file = $cacheDir . \DIRECTORY_SEPARATOR . $generation . \DIRECTORY_SEPARATOR . self::METADATA;
        $php = "<?php\nreturn [\n"
            . "    '_version' => {$version},\n"
            . "    '_middleware' => " . \var_export($middleware, true) . ",\n"
            . "];\n";
        matcher_write_validated_atomic_php_file(
            $file,
            $php,
            static function (array $blob) use ($middleware, $version): void {
                if (($blob['_version'] ?? null) !== $version || ($blob['_middleware'] ?? null) !== $middleware) {
                    throw new \UnexpectedValueException('Generated sharded metadata is invalid.');
                }
            },
        );
    }
}
