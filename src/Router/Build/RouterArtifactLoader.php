<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use RuntimeException;
use UnexpectedValueException;

/** Production artifact loader with normal and trusted-prevalidated trust paths. */
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

        return $this->loadVerifiedPayload($path, $meta, $expectedEnvironment, $expectedConfigFingerprint);
    }

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

        return $this->loadTrustedPayload($path, $meta, $expectedEnvironment, $expectedConfigFingerprint);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,array{0:string,1:string|null}>
     */
    private static function aliasPayloads(array $payload, string $field): array
    {
        $aliases = [];
        foreach (self::arrayField($payload, $field) as $name => $tuple) {
            if (!is_string($name) || !is_array($tuple)) {
                throw new UnexpectedValueException("Malformed Webrick router artifact field '{$field}'.");
            }
            $path = $tuple[0] ?? null;
            $domain = $tuple[1] ?? null;
            if (!is_string($path) || ($domain !== null && !is_string($domain))) {
                throw new UnexpectedValueException("Malformed Webrick router artifact field '{$field}'.");
            }
            $aliases[$name] = [$path, $domain];
        }

        return $aliases;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<array-key,mixed>
     */
    private static function arrayField(array $payload, string $field): array
    {
        $value = $payload[$field] ?? null;
        if (!is_array($value)) {
            throw new UnexpectedValueException("Malformed Webrick router artifact field '{$field}'.");
        }

        return $value;
    }

    private static function assertExpectedIdentity(string $environment, string $configFingerprint): void
    {
        if (trim($environment) === '' || trim($configFingerprint) === '') {
            throw new UnexpectedValueException('Expected environment and configuration fingerprint must be non-empty.');
        }
    }

    /** @param array{format:int,environment:string,config_fingerprint:string,artifact_fingerprint:string,sha256:string} $meta */
    private static function assertMetaIdentity(
        array $meta,
        string $expectedEnvironment,
        string $expectedConfigFingerprint,
    ): void {
        if ($meta['environment'] !== $expectedEnvironment) {
            throw new RuntimeException('Webrick router artifact environment mismatch.');
        }
        if (!hash_equals($expectedConfigFingerprint, $meta['config_fingerprint'])) {
            throw new RuntimeException('Webrick router artifact configuration fingerprint mismatch.');
        }
    }

    /** @param array{format:int,environment:string,config_fingerprint:string,artifact_fingerprint:string,sha256:string} $meta */
    private static function assertArtifactIdentity(CompiledRouterArtifact $artifact, array $meta): void
    {
        if (
            $artifact->environment !== $meta['environment']
            || !hash_equals($artifact->configFingerprint, $meta['config_fingerprint'])
            || !hash_equals($artifact->artifactFingerprint, $meta['artifact_fingerprint'])
        ) {
            throw new RuntimeException('Webrick router artifact metadata/payload mismatch.');
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<mixed>
     */
    private static function listField(array $payload, string $field): array
    {
        return array_values(self::arrayField($payload, $field));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,array<string,mixed>>
     */
    private static function planPayloads(array $payload, string $field): array
    {
        $plans = [];
        foreach (self::arrayField($payload, $field) as $routeId => $plan) {
            if (!is_string($routeId) || !is_array($plan)) {
                throw new UnexpectedValueException("Malformed Webrick router artifact field '{$field}'.");
            }

            $normalized = [];
            foreach ($plan as $key => $value) {
                if (!is_string($key)) {
                    throw new UnexpectedValueException("Malformed Webrick router artifact field '{$field}'.");
                }
                $normalized[$key] = $value;
            }
            $plans[$routeId] = $normalized;
        }

        return $plans;
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<string>
     */
    private static function stringListField(array $payload, string $field): array
    {
        $values = [];
        foreach (self::arrayField($payload, $field) as $value) {
            if (!is_string($value)) {
                throw new UnexpectedValueException("Malformed Webrick router artifact field '{$field}'.");
            }
            $values[] = $value;
        }

        return $values;
    }

    /** @param array<string,mixed> $payload */
    private function calculatePayloadFingerprint(array $payload): string
    {
        $environment = $payload['environment'] ?? null;
        $configFingerprint = $payload['config_fingerprint'] ?? null;
        $hasDomainRoutes = $payload['has_domain_routes'] ?? null;
        if (!is_string($environment) || !is_string($configFingerprint) || !is_bool($hasDomainRoutes)) {
            throw new UnexpectedValueException('Malformed Webrick router artifact identity fields.');
        }

        return RouterArtifactCompiler::fingerprintPayload(
            $environment,
            $configFingerprint,
            $hasDomainRoutes,
            self::listField($payload, 'routes'),
            self::planPayloads($payload, 'plans'),
            self::aliasPayloads($payload, 'aliases'),
            self::listField($payload, 'pre_global'),
            self::listField($payload, 'post_global'),
            self::stringListField($payload, 'pre_global_tags'),
            self::stringListField($payload, 'post_global_tags'),
        );
    }

    /**
     * @param array{format:int,environment:string,config_fingerprint:string,artifact_fingerprint:string,sha256:string} $meta
     */
    private function loadTrustedPayload(
        string $path,
        array $meta,
        string $expectedEnvironment,
        string $expectedConfigFingerprint,
    ): CompiledRouterArtifact {
        self::assertMetaIdentity($meta, $expectedEnvironment, $expectedConfigFingerprint);
        $payload = $this->requirePayload($path);

        // The caller supplied the artifact SHA-256 from trusted deployment metadata,
        // and it already matched the immutable sidecar. Do not traverse the complete
        // route/plan graph and recalculate its build-time fingerprint on every request.
        $payloadFingerprint = $payload['artifact_fingerprint'] ?? null;
        if (!is_string($payloadFingerprint) || !hash_equals($meta['artifact_fingerprint'], $payloadFingerprint)) {
            throw new RuntimeException('Webrick router artifact fingerprint metadata mismatch.');
        }

        $artifact = CompiledRouterArtifact::fromPayload($payload);
        self::assertArtifactIdentity($artifact, $meta);

        return $artifact;
    }

    /**
     * @param array{format:int,environment:string,config_fingerprint:string,artifact_fingerprint:string,sha256:string} $meta
     */
    private function loadVerifiedPayload(
        string $path,
        array $meta,
        string $expectedEnvironment,
        string $expectedConfigFingerprint,
    ): CompiledRouterArtifact {
        self::assertMetaIdentity($meta, $expectedEnvironment, $expectedConfigFingerprint);
        $payload = $this->requirePayload($path);

        $calculated = $this->calculatePayloadFingerprint($payload);
        $payloadFingerprint = $payload['artifact_fingerprint'] ?? null;
        if (!is_string($payloadFingerprint)
            || !hash_equals($meta['artifact_fingerprint'], $payloadFingerprint)
            || !hash_equals($calculated, $payloadFingerprint)
        ) {
            throw new RuntimeException('Webrick router artifact fingerprint mismatch.');
        }

        $artifact = CompiledRouterArtifact::fromPayload($payload);
        self::assertArtifactIdentity($artifact, $meta);

        return $artifact;
    }

    /** @return array<string,mixed> */
    private function requirePayload(string $path): array
    {
        $payload = require $path;
        if (!is_array($payload)) {
            throw new UnexpectedValueException('Compiled Webrick router artifact must return an array.');
        }

        foreach ($payload as $key => $_value) {
            if (!is_string($key)) {
                throw new UnexpectedValueException('Compiled Webrick router artifact must use string keys.');
            }
        }

        return $payload;
    }

    /** @return array{format:int,environment:string,config_fingerprint:string,artifact_fingerprint:string,sha256:string} */
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

        return [
            'format' => $meta['format'],
            'environment' => $meta['environment'],
            'config_fingerprint' => $meta['config_fingerprint'],
            'artifact_fingerprint' => $meta['artifact_fingerprint'],
            'sha256' => $meta['sha256'],
        ];
    }
}
