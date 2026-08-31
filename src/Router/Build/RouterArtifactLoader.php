<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use RuntimeException;
use UnexpectedValueException;

/**
 * Production artifact loader with normal and trusted-prevalidated trust paths.
 */
final class RouterArtifactLoader
{
    public function load(
        string $path,
        string $expectedEnvironment,
        string $expectedConfigFingerprint,
    ): CompiledRouterArtifact {
        self::assertExpectedIdentity($expectedEnvironment, $expectedConfigFingerprint);
        $meta = $this->readMeta($path);
        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256) || !hash_equals($meta['sha256'], $sha256)) {
            throw new RuntimeException('Webrick router artifact SHA-256 mismatch.');
        }

        return $this->loadPayload($path, $meta, $expectedEnvironment, $expectedConfigFingerprint);
    }

    /**
     * Use only when the artifact directory is immutable and the trusted digest
     * comes from deployment control-plane metadata.
     */
    public function loadPrevalidated(
        string $path,
        string $trustedSha256,
        string $expectedEnvironment,
        string $expectedConfigFingerprint,
    ): CompiledRouterArtifact {
        self::assertExpectedIdentity($expectedEnvironment, $expectedConfigFingerprint);
        if (!preg_match('/^[a-f0-9]{64}$/D', $trustedSha256)) {
            throw new UnexpectedValueException('Trusted Webrick SHA-256 must be a lowercase hexadecimal digest.');
        }

        $meta = $this->readMeta($path);
        if (!hash_equals($trustedSha256, $meta['sha256'])) {
            throw new RuntimeException('Trusted Webrick digest does not match the release manifest.');
        }

        return $this->loadPayload($path, $meta, $expectedEnvironment, $expectedConfigFingerprint);
    }

    private static function assertExpectedIdentity(string $environment, string $configFingerprint): void
    {
        if (trim($environment) === '' || trim($configFingerprint) === '') {
            throw new UnexpectedValueException('Expected environment and configuration fingerprint must be non-empty.');
        }
    }

    /**
     * @param array{format:int,environment:string,config_fingerprint:string,artifact_fingerprint:string,sha256:string} $meta
     */
    private function loadPayload(
        string $path,
        array $meta,
        string $expectedEnvironment,
        string $expectedConfigFingerprint,
    ): CompiledRouterArtifact {
        if ($meta['environment'] !== $expectedEnvironment) {
            throw new RuntimeException('Webrick router artifact environment mismatch.');
        }
        if (!hash_equals($expectedConfigFingerprint, $meta['config_fingerprint'])) {
            throw new RuntimeException('Webrick router artifact configuration fingerprint mismatch.');
        }

        $payload = require $path;
        if (!is_array($payload)) {
            throw new UnexpectedValueException('Compiled Webrick router artifact must return an array.');
        }

        foreach ($payload as $key => $_value) {
            if (!is_string($key)) {
                throw new UnexpectedValueException('Compiled Webrick router artifact must use string keys.');
            }
        }

        $artifact = CompiledRouterArtifact::fromPayload($payload);
        if (
            $artifact->environment !== $meta['environment']
            || !hash_equals($artifact->configFingerprint, $meta['config_fingerprint'])
            || !hash_equals($artifact->artifactFingerprint, $meta['artifact_fingerprint'])
            || !hash_equals($artifact->artifactFingerprint, $artifact->calculatedFingerprint())
        ) {
            throw new RuntimeException('Webrick router artifact metadata/payload mismatch.');
        }

        return $artifact;
    }

    /**
     * @return array{format:int,environment:string,config_fingerprint:string,artifact_fingerprint:string,sha256:string}
     */
    private function readMeta(string $path): array
    {
        $metaPath = $path . '.meta.json';
        if (!is_file($path) || !is_file($metaPath)) {
            throw new RuntimeException('Compiled Webrick router artifact or metadata is missing.');
        }

        $json = file_get_contents($metaPath);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to read Webrick router artifact metadata.');
        }
        $meta = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (
            !is_array($meta)
            || ($meta['format'] ?? null) !== CompiledRouterArtifact::FORMAT_VERSION
            || !is_string($meta['environment'] ?? null)
            || !is_string($meta['config_fingerprint'] ?? null)
            || !is_string($meta['artifact_fingerprint'] ?? null)
            || !is_string($meta['sha256'] ?? null)
        ) {
            throw new UnexpectedValueException('Malformed Webrick router artifact metadata.');
        }

        /** @var array{format:int,environment:string,config_fingerprint:string,artifact_fingerprint:string,sha256:string} $meta */
        return $meta;
    }
}
