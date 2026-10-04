<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use Closure;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Request\Request;
use RuntimeException;

/**
 * Coordinates, but does not duplicate, the InterMix and Webrick compilers.
 */
final readonly class ReleaseCompiler
{
    public const int RELEASE_FORMAT = 3;

    public function __construct(
        private RouteCompiler $routes = new RouteCompiler(),
        private RouterArtifactCompiler $routerArtifacts = new RouterArtifactCompiler(),
    ) {}

    /** @param array<string,mixed> $manifest */
    public static function fingerprintManifest(array $manifest): string
    {
        unset($manifest['release_fingerprint']);
        $encoded = json_encode(
            self::canonicalize($manifest),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return hash('xxh128', $encoded);
    }

    public static function runtimeManifestPath(string $releaseManifestPath): string
    {
        $path = trim($releaseManifestPath);
        if ($path === '') {
            throw new \InvalidArgumentException('Release manifest path must not be empty.');
        }

        return str_ends_with(strtolower($path), '.json')
            ? substr($path, 0, -5) . '.php'
            : $path . '.php';
    }

    /**
     * Route registration intentionally runs before InterMix validation/compile.
     * Hosts may use $enrichGraph to contribute route-referenced controllers or
     * middleware to the same builder, then both artifacts are emitted from the
     * already-discovered RouterBuildResult.
     *
     * @param array<string,mixed> $registrarOptions
     * @param list<mixed> $preGlobal
     * @param list<mixed> $postGlobal
     * @param list<string> $preGlobalTags
     * @param list<string> $postGlobalTags
     * @param null|Closure(ContainerBuilder,RouterBuildResult):void $enrichGraph
     * @return array<string,mixed>
     */
    public function compile(
        ContainerBuilder $builder,
        Closure $register,
        string $environment,
        string $configFingerprint,
        string $intermixPath,
        string $routerPath,
        string $releaseManifestPath,
        array $registrarOptions = [],
        array $preGlobal = [],
        array $postGlobal = [],
        array $preGlobalTags = ['webrick.middleware.pre'],
        array $postGlobalTags = ['webrick.middleware.post'],
        ?Closure $enrichGraph = null,
    ): array {
        $builder->input(Request::class);

        $routerBuild = $this->routes->compile(
            register: $register,
            environment: $environment,
            configFingerprint: $configFingerprint,
            registrarOptions: $registrarOptions,
            preGlobal: $preGlobal,
            postGlobal: $postGlobal,
            preGlobalTags: $preGlobalTags,
            postGlobalTags: $postGlobalTags,
        );

        if ($enrichGraph !== null) {
            $enrichGraph($builder, $routerBuild);
        }

        $builder->validate(strict: true);
        $intermix = $builder->compile($intermixPath);
        $webrick = $this->routerArtifacts->compile($routerBuild, $routerPath);

        $manifest = [
            'format' => self::RELEASE_FORMAT,
            'environment' => $environment,
            'config_fingerprint' => $configFingerprint,
            'intermix' => [
                'path' => $intermixPath,
                'digest' => $intermix['digest'],
                'graph' => $intermix['graph'],
                'build' => $intermix['build'],
                'artifact' => basename($intermix['artifact']),
                'compiled' => $intermix['compiled'],
                'skipped' => $intermix['skipped'],
            ],
            'webrick' => $webrick,
        ];
        $manifest['release_fingerprint'] = self::fingerprintManifest($manifest);

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $this->writeAtomic($releaseManifestPath, $json);

        $runtimeManifestPath = self::runtimeManifestPath($releaseManifestPath);
        $runtime = "<?php\n\ndeclare(strict_types=1);\n\nreturn "
            . var_export($manifest, true)
            . ";\n";
        $this->writeAtomic($runtimeManifestPath, $runtime);

        return $manifest + [
            'release_manifest' => $releaseManifestPath,
            'release_runtime_manifest' => $runtimeManifestPath,
        ];
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            $value[$key] = self::canonicalize($entry);
        }

        return $value;
    }

    private function writeAtomic(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create release-manifest directory '{$directory}'.");
        }

        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Unable to publish release manifest '{$path}'.");
        }
    }
}
