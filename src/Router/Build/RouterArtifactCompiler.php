<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use Infocyph\Webrick\Router\Build\Artifact\ArtifactValueCodec;
use Infocyph\Webrick\Router\Build\Artifact\MatcherRouteMetadata;
use RuntimeException;

/** Emits the Webrick half of a coordinated production release bundle. */
final class RouterArtifactCompiler
{
    /**
     * @param list<mixed> $routes
     * @param array<string,array<string,mixed>> $plans
     * @param array<string,array{0:string,1:?string}> $aliases
     * @param list<mixed> $preGlobal
     * @param list<mixed> $postGlobal
     * @param list<string> $preGlobalTags
     * @param list<string> $postGlobalTags
     */
    public static function fingerprintPayload(
        string $environment,
        string $configFingerprint,
        bool $hasDomainRoutes,
        array $routes,
        array $plans,
        array $aliases,
        array $preGlobal,
        array $postGlobal,
        array $preGlobalTags,
        array $postGlobalTags,
    ): string {
        ksort($plans, SORT_STRING);
        ksort($aliases, SORT_STRING);

        return hash('sha256', serialize([
            CompiledRouterArtifact::FORMAT_VERSION,
            $environment,
            $configFingerprint,
            $hasDomainRoutes,
            $routes,
            $plans,
            $aliases,
            $preGlobal,
            $postGlobal,
            $preGlobalTags,
            $postGlobalTags,
        ]));
    }

    /** @return array{path:string,meta:string,sha256:string,fingerprint:string,routes:int} */
    public function compile(RouterBuildResult $build, string $path): array
    {
        $plans = [];
        foreach ($build->plans as $routeId => $plan) {
            $plans[$routeId] = $plan->toPayload();
        }
        $routes = array_map(MatcherRouteMetadata::encode(...), $build->routes->all());
        $preGlobal = array_map(ArtifactValueCodec::encode(...), $build->preGlobal);
        $postGlobal = array_map(ArtifactValueCodec::encode(...), $build->postGlobal);
        $fingerprint = self::fingerprintPayload(
            $build->environment,
            $build->configFingerprint,
            $build->hasDomainRoutes,
            $routes,
            $plans,
            $build->aliases,
            $preGlobal,
            $postGlobal,
            $build->preGlobalTags,
            $build->postGlobalTags,
        );

        $payload = [
            'format' => CompiledRouterArtifact::FORMAT_VERSION,
            'environment' => $build->environment,
            'config_fingerprint' => $build->configFingerprint,
            'artifact_fingerprint' => $fingerprint,
            'has_domain_routes' => $build->hasDomainRoutes,
            'routes' => $routes,
            'plans' => $plans,
            'aliases' => $build->aliases,
            'pre_global' => $preGlobal,
            'post_global' => $postGlobal,
            'pre_global_tags' => $build->preGlobalTags,
            'post_global_tags' => $build->postGlobalTags,
        ];

        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($payload, true) . ";\n";
        $this->writeAtomic($path, $php);
        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256)) {
            throw new RuntimeException('Unable to hash generated Webrick router artifact.');
        }

        $metaPath = $path . '.meta.json';
        $meta = json_encode([
            'format' => CompiledRouterArtifact::FORMAT_VERSION,
            'environment' => $build->environment,
            'config_fingerprint' => $build->configFingerprint,
            'artifact_fingerprint' => $fingerprint,
            'sha256' => $sha256,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $this->writeAtomic($metaPath, $meta);

        return [
            'path' => $path,
            'meta' => $metaPath,
            'sha256' => $sha256,
            'fingerprint' => $fingerprint,
            'routes' => count($build->routes->all()),
        ];
    }

    public function fingerprint(RouterBuildResult $build): string
    {
        $plans = [];
        foreach ($build->plans as $routeId => $plan) {
            $plans[$routeId] = $plan->toPayload();
        }

        return self::fingerprintPayload(
            $build->environment,
            $build->configFingerprint,
            $build->hasDomainRoutes,
            array_map(MatcherRouteMetadata::encode(...), $build->routes->all()),
            $plans,
            $build->aliases,
            array_map(ArtifactValueCodec::encode(...), $build->preGlobal),
            array_map(ArtifactValueCodec::encode(...), $build->postGlobal),
            $build->preGlobalTags,
            $build->postGlobalTags,
        );
    }

    private function writeAtomic(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create Webrick artifact directory '{$directory}'.");
        }

        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write Webrick artifact '{$temporary}'.");
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Unable to publish Webrick artifact '{$path}'.");
        }
    }
}
