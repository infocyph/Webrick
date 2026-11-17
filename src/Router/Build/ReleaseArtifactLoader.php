<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\Webrick\Router\Runtime\RuntimeStageProfiler;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Loads one coordinated Webrick/InterMix release and rejects stale or mixed
 * artifact metadata before either runtime is exposed to the host.
 */
final readonly class ReleaseArtifactLoader
{
    public function __construct(private RouterArtifactLoader $routerArtifacts = new RouterArtifactLoader()) {}

    /**
     * @return array{container:ProductionContainer,artifact:CompiledRouterArtifact,manifest:array<string,mixed>}
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     * @throws \InvalidArgumentException
     */
    public function load(
        ContainerBuilder $builder,
        string $releaseManifestPath,
        string $expectedEnvironment,
        string $expectedConfigFingerprint,
        ?RuntimeStageProfiler $profiler = null,
    ): array {
        $manifest = $this->readManifest($releaseManifestPath);
        $profiler?->mark('release_manifest_load');
        $this->assertReleaseIdentity($manifest, $expectedEnvironment, $expectedConfigFingerprint);

        $intermix = $this->intermixMetadata($manifest);
        $webrick = $this->webrickMetadata($manifest);

        $this->assertInterMixArtifact($intermix);
        $this->assertWebrickArtifactDigest($webrick);
        $container = $builder->production($intermix['path']);
        $profiler?->mark('intermix_runtime_load');

        $artifact = $this->routerArtifacts->load(
            $webrick['path'],
            $expectedEnvironment,
            $expectedConfigFingerprint,
        );
        $profiler?->mark('router_artifact_load');
        $this->assertWebrickArtifact($artifact, $webrick);

        return [
            'container' => $container,
            'artifact' => $artifact,
            'manifest' => $manifest,
        ];
    }

    /**
     * @param array{path:string,digest:string,source_sha256:?string,graph:string,build:string,artifact:string,compiled:list<string>,skipped:array<string,string>} $expected
     * @throws \RuntimeException
     */
    private function assertInterMixArtifact(array $expected): void
    {
        if (!is_link($expected['path'])) {
            throw new RuntimeException('InterMix 11 release artifact must be an active runtime symlink.');
        }

        $artifactPath = realpath($expected['path']);
        if ($artifactPath === false || !is_file($artifactPath) || !is_readable($artifactPath)) {
            throw new RuntimeException('InterMix release artifact is missing or unreadable.');
        }

        $algorithm = $expected['source_sha256'] === null ? 'xxh128' : 'sha256';
        $expectedDigest = $expected['source_sha256'] ?? $expected['digest'];
        $digest = hash_file($algorithm, $artifactPath);
        if (!is_string($digest) || !hash_equals($expectedDigest, $digest)) {
            throw new RuntimeException('InterMix release artifact digest mismatch.');
        }
        if (basename($artifactPath) !== $expected['artifact']) {
            throw new RuntimeException('InterMix release artifact name mismatch.');
        }
        if (basename(dirname($artifactPath)) !== $expected['build']) {
            throw new RuntimeException('InterMix release build identity mismatch.');
        }
    }

    /**
     * @param array<string,mixed> $manifest
     * @throws \RuntimeException
     */
    private function assertReleaseIdentity(
        array $manifest,
        string $expectedEnvironment,
        string $expectedConfigFingerprint,
    ): void {
        if (($manifest['format'] ?? null) !== ReleaseCompiler::RELEASE_FORMAT) {
            throw new RuntimeException('Unsupported Webrick coordinated release manifest format.');
        }
        if (($manifest['environment'] ?? null) !== $expectedEnvironment) {
            throw new RuntimeException('Coordinated release environment mismatch.');
        }
        $fingerprint = $manifest['config_fingerprint'] ?? null;
        if (!is_string($fingerprint) || !hash_equals($expectedConfigFingerprint, $fingerprint)) {
            throw new RuntimeException('Coordinated release configuration fingerprint mismatch.');
        }

        $releaseFingerprint = $manifest['release_fingerprint'] ?? null;
        if (!is_string($releaseFingerprint)
            || preg_match('/^[a-f0-9]{32}$/D', $releaseFingerprint) !== 1
            || !hash_equals($releaseFingerprint, ReleaseCompiler::fingerprintManifest($manifest))
        ) {
            throw new RuntimeException('Coordinated release fingerprint mismatch.');
        }
    }

    /**
     * @param array{path:string,meta:string,digest:string,source_sha256:?string,fingerprint:string,routes:int} $expected
     * @throws \RuntimeException
     */
    private function assertWebrickArtifact(CompiledRouterArtifact $artifact, array $expected): void
    {
        if (!hash_equals($expected['fingerprint'], $artifact->artifactFingerprint)) {
            throw new RuntimeException('Webrick router artifact fingerprint does not match the coordinated release.');
        }
        if (count($artifact->routes()) !== $expected['routes']) {
            throw new RuntimeException('Webrick router route count does not match the coordinated release.');
        }
    }

    /**
     * Verify the manifest's byte identity before loading any executable artifacts.
     *
     * @param array{path:string,meta:string,digest:string,source_sha256:?string,fingerprint:string,routes:int} $expected
     * @throws \RuntimeException
     */
    private function assertWebrickArtifactDigest(array $expected): void
    {
        if ($expected['meta'] !== $expected['path'] . '.meta.json') {
            throw new RuntimeException('Webrick router metadata path does not match the coordinated release artifact.');
        }
        $algorithm = $expected['source_sha256'] === null ? 'xxh128' : 'sha256';
        $expectedDigest = $expected['source_sha256'] ?? $expected['digest'];
        $digest = hash_file($algorithm, $expected['path']);
        if (!is_string($digest) || !hash_equals($expectedDigest, $digest)) {
            throw new RuntimeException('Webrick router artifact digest does not match the coordinated release.');
        }
    }

    /**
     * @return array<string,mixed>
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    private function decodeJsonFile(string $path, string $label): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("{$label} is missing or unreadable.");
        }
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException("Unable to read {$label}.");
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException("{$label} is invalid JSON.", previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new UnexpectedValueException("{$label} must contain an object.");
        }

        $result = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new UnexpectedValueException("{$label} must use string keys.");
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array{path:string,digest:string,source_sha256:?string,graph:string,build:string,artifact:string,compiled:list<string>,skipped:array<string,string>}
     * @throws \UnexpectedValueException
     */
    private function intermixMetadata(array $manifest): array
    {
        $metadata = $manifest['intermix'] ?? null;
        if (!is_array($metadata)) {
            throw new UnexpectedValueException('Coordinated release has invalid InterMix metadata.');
        }

        foreach (['path', 'digest', 'graph', 'build', 'artifact'] as $field) {
            if (!is_string($metadata[$field] ?? null)) {
                throw new UnexpectedValueException("Coordinated release InterMix {$field} is invalid.");
            }
        }
        foreach (['digest', 'graph', 'build'] as $field) {
            $value = $metadata[$field] ?? null;
            if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
                throw new UnexpectedValueException("Coordinated release InterMix {$field} must be xxh128.");
            }
        }

        $sourceSha256 = self::optionalSourceSha256($metadata, 'InterMix');

        $compiled = $this->stringList($metadata['compiled'] ?? null, 'InterMix compiled IDs');
        $skipped = $this->stringMap($metadata['skipped'] ?? null, 'InterMix skipped IDs');

        return [
            'path' => $metadata['path'],
            'digest' => $metadata['digest'],
            'source_sha256' => $sourceSha256,
            'graph' => $metadata['graph'],
            'build' => $metadata['build'],
            'artifact' => $metadata['artifact'],
            'compiled' => $compiled,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param array<array-key,mixed> $metadata
     * @throws \UnexpectedValueException
     */
    private static function optionalSourceSha256(array $metadata, string $label): ?string
    {
        if (!array_key_exists('source_sha256', $metadata)) {
            return null;
        }

        $sourceSha256 = $metadata['source_sha256'];
        if (!is_string($sourceSha256) || preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) !== 1) {
            throw new UnexpectedValueException("Coordinated release {$label} source_sha256 must be SHA-256.");
        }

        return $sourceSha256;
    }

    /**
     * @return array<string,mixed>
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    private function readManifest(string $path): array
    {
        return $this->decodeJsonFile($path, 'Webrick coordinated release manifest');
    }

    /**
     * @return list<string>
     * @throws \UnexpectedValueException
     */
    private function stringList(mixed $value, string $label): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new UnexpectedValueException("{$label} must be a list.");
        }
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new UnexpectedValueException("{$label} must contain strings.");
            }
        }

        /** @var list<string> $value Validated element-by-element above. */
        return $value;
    }

    /**
     * @return array<string,string>
     * @throws \UnexpectedValueException
     */
    private function stringMap(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            throw new UnexpectedValueException("{$label} must be an object.");
        }
        $result = [];
        foreach ($value as $key => $entry) {
            if (!is_string($entry)) {
                throw new UnexpectedValueException("{$label} must contain string entries.");
            }
            $result[(string) $key] = $entry;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array{path:string,meta:string,digest:string,source_sha256:?string,fingerprint:string,routes:int}
     * @throws \UnexpectedValueException
     */
    private function webrickMetadata(array $manifest): array
    {
        $metadata = $manifest['webrick'] ?? null;
        if (!is_array($metadata)) {
            throw new UnexpectedValueException('Coordinated release has invalid Webrick metadata.');
        }
        foreach (['path', 'meta', 'digest', 'fingerprint'] as $field) {
            if (!is_string($metadata[$field] ?? null)) {
                throw new UnexpectedValueException("Coordinated release Webrick {$field} is invalid.");
            }
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $metadata['digest']) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $metadata['fingerprint']) !== 1
            || !is_int($metadata['routes'] ?? null)
            || $metadata['routes'] < 0
        ) {
            throw new UnexpectedValueException('Coordinated release has malformed Webrick artifact identity.');
        }

        $sourceSha256 = self::optionalSourceSha256($metadata, 'Webrick');

        return [
            'path' => $metadata['path'],
            'meta' => $metadata['meta'],
            'digest' => $metadata['digest'],
            'source_sha256' => $sourceSha256,
            'fingerprint' => $metadata['fingerprint'],
            'routes' => $metadata['routes'],
        ];
    }
}
