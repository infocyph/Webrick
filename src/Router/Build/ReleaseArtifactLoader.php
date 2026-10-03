<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\ProductionContainer;
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
     */
    public function load(
        ContainerBuilder $builder,
        string $releaseManifestPath,
        string $expectedEnvironment,
        string $expectedConfigFingerprint,
    ): array {
        $manifest = $this->readManifest($releaseManifestPath);
        $this->assertReleaseIdentity($manifest, $expectedEnvironment, $expectedConfigFingerprint);

        $intermix = $this->intermixMetadata($manifest);
        $webrick = $this->webrickMetadata($manifest);

        $this->assertInterMixArtifact($intermix);
        $container = $builder->production($intermix['path']);

        $artifact = $this->routerArtifacts->load(
            $webrick['path'],
            $expectedEnvironment,
            $expectedConfigFingerprint,
        );
        $this->assertWebrickArtifact($artifact, $webrick);

        return [
            'container' => $container,
            'artifact' => $artifact,
            'manifest' => $manifest,
        ];
    }

    /**
     * @param array{path:string,digest:string,graph:string,build:string,artifact:string,compiled:list<string>,skipped:array<string,string>} $expected
     */
    private function assertInterMixArtifact(array $expected): void {
        if (!is_link($expected['path'])) {
            throw new RuntimeException('InterMix 11 release artifact must be an active runtime symlink.');
        }

        $artifactPath = realpath($expected['path']);
        if ($artifactPath === false || !is_file($artifactPath) || !is_readable($artifactPath)) {
            throw new RuntimeException('InterMix release artifact is missing or unreadable.');
        }

        $digest = hash_file('xxh128', $artifactPath);
        if (!is_string($digest) || !hash_equals($expected['digest'], $digest)) {
            throw new RuntimeException('InterMix release artifact digest mismatch.');
        }
        if (basename($artifactPath) !== $expected['artifact']) {
            throw new RuntimeException('InterMix release artifact name mismatch.');
        }
        if (basename(dirname($artifactPath)) !== $expected['build']) {
            throw new RuntimeException('InterMix release build identity mismatch.');
        }
    }

    /** @param array<string,mixed> $manifest */
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
     * @param array{path:string,meta:string,digest:string,fingerprint:string,routes:int} $expected
     */
    private function assertWebrickArtifact(CompiledRouterArtifact $artifact, array $expected): void {
        $digest = hash_file('xxh128', $expected['path']);
        if (!is_string($digest) || !hash_equals($expected['digest'], $digest)) {
            throw new RuntimeException('Webrick router artifact digest does not match the coordinated release.');
        }
        if (!hash_equals($expected['fingerprint'], $artifact->artifactFingerprint)) {
            throw new RuntimeException('Webrick router artifact fingerprint does not match the coordinated release.');
        }
        if ($expected['meta'] !== $expected['path'] . '.meta.json') {
            throw new RuntimeException('Webrick router metadata path does not match the coordinated release artifact.');
        }
        if (count($artifact->routes()) !== $expected['routes']) {
            throw new RuntimeException('Webrick router route count does not match the coordinated release.');
        }
    }

    /** @return array<string,mixed> */
    private function decodeJsonFile(string $path, string $label): array {
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
     * @return array{path:string,digest:string,graph:string,build:string,artifact:string,compiled:list<string>,skipped:array<string,string>}
     */
    private function intermixMetadata(array $manifest): array {
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

        $compiled = $this->stringList($metadata['compiled'] ?? null, 'InterMix compiled IDs');
        $skipped = $this->stringMap($metadata['skipped'] ?? null, 'InterMix skipped IDs');

        return [
            'path' => $metadata['path'],
            'digest' => $metadata['digest'],
            'graph' => $metadata['graph'],
            'build' => $metadata['build'],
            'artifact' => $metadata['artifact'],
            'compiled' => $compiled,
            'skipped' => $skipped,
        ];
    }

    /** @return array<string,mixed> */
    private function readManifest(string $path): array {
        return $this->decodeJsonFile($path, 'Webrick coordinated release manifest');
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $label): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw new UnexpectedValueException("{$label} must be a list.");
        }
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new UnexpectedValueException("{$label} must contain strings.");
            }
        }

        return $value;
    }

    /** @return array<string,string> */
    private function stringMap(mixed $value, string $label): array {
        if (!is_array($value)) {
            throw new UnexpectedValueException("{$label} must be an object.");
        }
        $result = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key) || !is_string($entry)) {
                throw new UnexpectedValueException("{$label} must contain string entries.");
            }
            $result[$key] = $entry;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array{path:string,meta:string,digest:string,fingerprint:string,routes:int}
     */
    private function webrickMetadata(array $manifest): array {
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

        return [
            'path' => $metadata['path'],
            'meta' => $metadata['meta'],
            'digest' => $metadata['digest'],
            'fingerprint' => $metadata['fingerprint'],
            'routes' => $metadata['routes'],
        ];
    }
}
