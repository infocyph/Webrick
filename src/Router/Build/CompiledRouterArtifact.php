<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use Infocyph\Webrick\Router\Build\Artifact\ArtifactValueCodec;
use Infocyph\Webrick\Router\Build\Artifact\MatcherRouteMetadata;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use UnexpectedValueException;

/** Immutable production payload with request-time hydration limited to the matched route. */
final class CompiledRouterArtifact
{
    public const int FORMAT_VERSION = 3;

    /** @var array<int,ExecutionPlan> */
    private array $decodedPlans = [];

    /** @var array<int,CompiledRoute> */
    private array $decodedRoutes = [];

    /** @var list<mixed>|null */
    private ?array $postGlobal = null;

    /** @var list<mixed>|null */
    private ?array $preGlobal = null;

    /** @var list<mixed> */
    private array $postGlobalPayload;

    /** @var list<mixed> */
    private array $preGlobalPayload;

    /** @var array<int,array<string,mixed>> */
    private array $planPayloadsByIndex;

    /** @var array<int,mixed> */
    private array $routePayloadsByIndex;

    /**
     * @param array<int,mixed> $routePayloadsByIndex
     * @param array<int,array<string,mixed>> $planPayloadsByIndex
     * @param array<string,array{0:string,1:string|null}> $aliases
     * @param list<mixed> $preGlobalPayload
     * @param list<mixed> $postGlobalPayload
     * @param list<string> $preGlobalTags
     * @param list<string> $postGlobalTags
     */
    private function __construct(
        array $routePayloadsByIndex,
        array $planPayloadsByIndex,
        public readonly array $aliases,
        array $preGlobalPayload,
        array $postGlobalPayload,
        public readonly array $preGlobalTags,
        public readonly array $postGlobalTags,
        public readonly bool $hasDomainRoutes,
        public readonly string $environment,
        public readonly string $configFingerprint,
        public readonly string $artifactFingerprint,
    ) {
        $this->routePayloadsByIndex = $routePayloadsByIndex;
        $this->planPayloadsByIndex = $planPayloadsByIndex;
        $this->preGlobalPayload = $preGlobalPayload;
        $this->postGlobalPayload = $postGlobalPayload;
    }

    /**
     * Trusted payloads receive constant-time header/top-level checks only. Full
     * route/plan validation belongs to compilation or the verified loader path.
     *
     * @param array<string,mixed> $payload
     */
    public static function fromPayload(array $payload, bool $trusted = false): self
    {
        [$hasDomainRoutes, $environment, $configFingerprint, $artifactFingerprint] = self::header($payload);

        $routes = self::arrayField($payload, 'routes_by_index');
        $plans = self::arrayField($payload, 'plans_by_index');
        $aliases = self::arrayField($payload, 'aliases');
        $preGlobal = self::arrayField($payload, 'pre_global');
        $postGlobal = self::arrayField($payload, 'post_global');
        $preGlobalTags = self::arrayField($payload, 'pre_global_tags');
        $postGlobalTags = self::arrayField($payload, 'post_global_tags');

        if (!$trusted) {
            self::validateAliases($aliases);
            self::validateTags($preGlobalTags);
            self::validateTags($postGlobalTags);
        }

        /** @var array<int,mixed> $routes */
        /** @var array<int,array<string,mixed>> $plans */
        /** @var array<string,array{0:string,1:string|null}> $aliases */
        /** @var list<mixed> $preGlobal */
        /** @var list<mixed> $postGlobal */
        /** @var list<string> $preGlobalTags */
        /** @var list<string> $postGlobalTags */
        $artifact = new self(
            routePayloadsByIndex: $routes,
            planPayloadsByIndex: $plans,
            aliases: $aliases,
            preGlobalPayload: $preGlobal,
            postGlobalPayload: $postGlobal,
            preGlobalTags: $preGlobalTags,
            postGlobalTags: $postGlobalTags,
            hasDomainRoutes: $hasDomainRoutes,
            environment: $environment,
            configFingerprint: $configFingerprint,
            artifactFingerprint: $artifactFingerprint,
        );

        if (!$trusted) {
            $artifact->validateRoutePlanTables();
        }

        return $artifact;
    }

    /** Artifact fingerprint was established before runtime hydration. */
    public function calculatedFingerprint(): string
    {
        return $this->artifactFingerprint;
    }

    public function hasGlobalMiddleware(): bool
    {
        return $this->preGlobalPayload !== []
            || $this->postGlobalPayload !== []
            || $this->preGlobalTags !== []
            || $this->postGlobalTags !== [];
    }

    public function planForIndex(int $routeIndex): ExecutionPlan
    {
        if (isset($this->decodedPlans[$routeIndex])) {
            return $this->decodedPlans[$routeIndex];
        }

        $payload = $this->planPayloadsByIndex[$routeIndex] ?? null;
        if (!is_array($payload)) {
            throw new UnexpectedValueException('Matched route index has no compiled execution plan.');
        }

        return $this->decodedPlans[$routeIndex] = ExecutionPlan::fromPayload($payload);
    }

    /** @return list<mixed> */
    public function postGlobal(): array
    {
        if ($this->postGlobal === null) {
            $this->postGlobal = self::decodedList($this->postGlobalPayload);
        }

        return $this->postGlobal;
    }

    /** @return list<mixed> */
    public function preGlobal(): array
    {
        if ($this->preGlobal === null) {
            $this->preGlobal = self::decodedList($this->preGlobalPayload);
        }

        return $this->preGlobal;
    }

    /** @return array<string,mixed> */
    public function routeAttributesForIndex(int $routeIndex): array
    {
        $route = $this->routeForIndex($routeIndex);
        $attributes = [];
        $cors = $route->getCorsPolicy();
        if ($cors !== null) {
            $attributes['cors_policy'] = $cors;
        }
        $produces = $route->getProduces();
        if ($produces !== null) {
            $attributes['produces'] = $produces;
        }

        return $attributes;
    }

    /**
     * Decode every matcher route only for cold/non-cached matcher construction.
     *
     * @return list<CompiledRoute>
     */
    public function routes(): array
    {
        $routes = [];
        $indexes = array_keys($this->routePayloadsByIndex);
        sort($indexes, SORT_NUMERIC);
        foreach ($indexes as $index) {
            $routes[] = $this->routeForIndex($index);
        }

        return $routes;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<array-key,mixed>
     */
    private static function arrayField(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            throw new UnexpectedValueException("Malformed Webrick router artifact field '{$key}'.");
        }

        return $value;
    }

    /**
     * @param list<mixed> $payload
     * @return list<mixed>
     */
    private static function decodedList(array $payload): array
    {
        $values = [];
        foreach ($payload as $value) {
            $values[] = ArtifactValueCodec::decode($value);
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{0:bool,1:string,2:string,3:string}
     */
    private static function header(array $payload): array
    {
        if (($payload['format'] ?? null) !== self::FORMAT_VERSION) {
            throw new UnexpectedValueException('Unsupported Webrick router artifact format.');
        }

        $environment = $payload['environment'] ?? null;
        $configFingerprint = $payload['config_fingerprint'] ?? null;
        $artifactFingerprint = $payload['artifact_fingerprint'] ?? null;
        $hasDomainRoutes = $payload['has_domain_routes'] ?? null;
        if (!is_string($environment) || $environment === '') {
            throw new UnexpectedValueException("Malformed Webrick router artifact field 'environment'.");
        }
        if (!is_string($configFingerprint) || $configFingerprint === '') {
            throw new UnexpectedValueException("Malformed Webrick router artifact field 'config_fingerprint'.");
        }
        if (!is_string($artifactFingerprint) || $artifactFingerprint === '') {
            throw new UnexpectedValueException("Malformed Webrick router artifact field 'artifact_fingerprint'.");
        }
        if (!is_bool($hasDomainRoutes)) {
            throw new UnexpectedValueException('Malformed Webrick domain-routing capability.');
        }

        return [$hasDomainRoutes, $environment, $configFingerprint, $artifactFingerprint];
    }

    /** @param array<array-key,mixed> $aliases */
    private static function validateAliases(array $aliases): void
    {
        foreach ($aliases as $name => $tuple) {
            if (!is_string($name) || $name === '' || !is_array($tuple)) {
                throw new UnexpectedValueException('Invalid alias index in Webrick router artifact.');
            }
            $path = $tuple[0] ?? null;
            $domain = $tuple[1] ?? null;
            if (!is_string($path) || ($domain !== null && !is_string($domain))) {
                throw new UnexpectedValueException('Invalid alias entry in Webrick router artifact.');
            }
        }
    }

    /** @param array<array-key,mixed> $tags */
    private static function validateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || $tag === '') {
                throw new UnexpectedValueException('Invalid middleware tag in Webrick router artifact.');
            }
        }
    }

    private function routeForIndex(int $routeIndex): CompiledRoute
    {
        if (isset($this->decodedRoutes[$routeIndex])) {
            return $this->decodedRoutes[$routeIndex];
        }

        $payload = $this->routePayloadsByIndex[$routeIndex] ?? null;
        $route = MatcherRouteMetadata::decode($payload);
        if ($route->getIndex() !== $routeIndex) {
            throw new UnexpectedValueException('Compiled route payload index mismatch.');
        }

        return $this->decodedRoutes[$routeIndex] = $route;
    }

    private function validateRoutePlanTables(): void
    {
        if (count($this->routePayloadsByIndex) !== count($this->planPayloadsByIndex)) {
            throw new UnexpectedValueException('Every compiled route must have exactly one execution plan.');
        }

        foreach ($this->routePayloadsByIndex as $index => $_payload) {
            if (!array_key_exists($index, $this->planPayloadsByIndex)) {
                throw new UnexpectedValueException('Compiled route/plan index mismatch.');
            }
            $route = $this->routeForIndex($index);
            $plan = $this->planForIndex($index);
            if ($plan->routeId !== RouteIdentity::forRoute($route)) {
                throw new UnexpectedValueException('Execution plan identity does not match its compiled route.');
            }
        }
    }
}
