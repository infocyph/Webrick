<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use Infocyph\Webrick\Router\Build\Artifact\ArtifactValueCodec;
use Infocyph\Webrick\Router\Build\Artifact\MatcherRouteMetadata;
use RuntimeException;

/** Emits the Webrick half of a coordinated production release bundle. */
final class RouterArtifactCompiler
{
    /** @return array{path:string,meta:string,sha256:string,fingerprint:string,routes:int} */
    public function compile(RouterBuildResult $build, string $path): array
    {
        $fingerprint = $this->fingerprint($build);
        $plans = [];
        foreach ($build->plans as $routeId => $plan) {
            $plans[$routeId] = $plan->toPayload();
        }

        $payload = [
            'format' => CompiledRouterArtifact::FORMAT_VERSION,
            'environment' => $build->environment,
            'config_fingerprint' => $build->configFingerprint,
            'artifact_fingerprint' => $fingerprint,
            'has_domain_routes' => $build->hasDomainRoutes,
            'routes' => array_map(MatcherRouteMetadata::encode(...), $build->routes->all()),
            'plans' => $plans,
            'aliases' => $build->aliases,
            'pre_global' => array_map(ArtifactValueCodec::encode(...), $build->preGlobal),
            'post_global' => array_map(ArtifactValueCodec::encode(...), $build->postGlobal),
            'pre_global_tags' => $build->preGlobalTags,
            'post_global_tags' => $build->postGlobalTags,
        ];

        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($payload, true) . ";\n";
        $this->writeAtomic($path, $php);
        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256) || $sha256 === '') {
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
        $routeMeta = [];
        foreach ($build->routes as $route) {
            $routeId = RouteIdentity::forRoute($route);
            $plan = $build->plans[$routeId] ?? throw new RuntimeException('Missing compiled execution plan.');
            $routeMeta[] = [
                $routeId,
                $route->getMethod(),
                $route->getDomain(),
                $route->getPath(),
                $plan->kind->value,
                $plan->terminalKind->value,
                $plan->capabilities,
                $plan->routeArguments,
            ];
        }

        return hash('sha256', serialize([
            CompiledRouterArtifact::FORMAT_VERSION,
            $build->environment,
            $build->configFingerprint,
            $routeMeta,
            $build->aliases,
            $build->preGlobalTags,
            $build->postGlobalTags,
            $build->hasDomainRoutes,
        ]));
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
