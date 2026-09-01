<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use Closure;
use Infocyph\InterMix\DI\ContainerBuilder;
use RuntimeException;

/**
 * Coordinates, but does not duplicate, the InterMix and Webrick compilers.
 */
final readonly class ReleaseCompiler
{
    private const int RELEASE_FORMAT = 2;

    public function __construct(
        private RouteCompiler $routes = new RouteCompiler(),
        private RouterArtifactCompiler $routerArtifacts = new RouterArtifactCompiler(),
    ) {}

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
     * @param array<string,mixed> $registrarOptions
     * @param list<mixed> $preGlobal
     * @param list<mixed> $postGlobal
     * @param list<string> $preGlobalTags
     * @param list<string> $postGlobalTags
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
    ): array {
        $builder->validate(strict: true);
        $intermix = $builder->compile($intermixPath);

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
        $webrick = $this->routerArtifacts->compile($routerBuild, $routerPath);

        $manifest = [
            'format' => self::RELEASE_FORMAT,
            'environment' => $environment,
            'config_fingerprint' => $configFingerprint,
            'intermix' => [
                'path' => $intermixPath,
                'digest' => $intermix['digest'],
                'compiled' => $intermix['compiled'],
                'skipped' => $intermix['skipped'],
            ],
            'webrick' => $webrick,
        ];

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
