<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use Infocyph\Webrick\Router\Build\Artifact\ArtifactValueCodec;
use Infocyph\Webrick\Router\Build\Artifact\MatcherRouteMetadata;
use Infocyph\Webrick\Router\Route\CompiledRoute;
use UnexpectedValueException;

/** Verified immutable Webrick runtime artifact loaded at process/worker boot. */
final readonly class CompiledRouterArtifact
{
    public const int FORMAT_VERSION = 2;
    /** @var array<int,ExecutionPlan> */ private array $plansByIndex;

    /** @param list<CompiledRoute> $routes @param array<string,ExecutionPlan> $plans @param array<string,array{0:string,1:?string}> $aliases @param list<mixed> $preGlobal @param list<mixed> $postGlobal @param list<string> $preGlobalTags @param list<string> $postGlobalTags */
    public function __construct(
        public array $routes,
        public array $plans,
        public array $aliases,
        public array $preGlobal,
        public array $postGlobal,
        public array $preGlobalTags,
        public array $postGlobalTags,
        public bool $hasDomainRoutes,
        public string $environment,
        public string $configFingerprint,
        public string $artifactFingerprint,
    ) {
        $plansByIndex=[];
        foreach($routes as $route){$index=$route->getIndex();if(isset($plansByIndex[$index])){throw new UnexpectedValueException('Duplicate compiled route index in router artifact.');}$routeId=RouteIdentity::forRoute($route);$plansByIndex[$index]=$plans[$routeId]??throw new UnexpectedValueException('Missing route execution plan.');}
        $this->plansByIndex=$plansByIndex;
    }

    /** @param array<string,mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        [$hasDomainRoutes,$environment,$configFingerprint,$artifactFingerprint]=self::header($payload);
        $routes=[];$routeIds=[];
        foreach(self::arrayField($payload,'routes') as $encoded){$route=MatcherRouteMetadata::decode($encoded);$routeId=RouteIdentity::forRoute($route);if(isset($routeIds[$routeId])){throw new UnexpectedValueException('Duplicate deterministic route identity in router artifact.');}$routeIds[$routeId]=true;$routes[]=$route;}
        $plans=[];
        foreach(self::arrayField($payload,'plans') as $routeId=>$planPayload){if(!is_string($routeId)||$routeId===''||!isset($routeIds[$routeId])){throw new UnexpectedValueException('Execution-plan table references an unknown route identity.');}$plan=ExecutionPlan::fromPayload($planPayload);if($plan->routeId!==$routeId){throw new UnexpectedValueException('Execution plan identity does not match its table key.');}$plans[$routeId]=$plan;}
        if(count($plans)!==count($routes)){throw new UnexpectedValueException('Every compiled route must have exactly one execution plan.');}
        return new self(routes:$routes,plans:$plans,aliases:self::aliases(self::arrayField($payload,'aliases')),preGlobal:self::decodedList(self::arrayField($payload,'pre_global')),postGlobal:self::decodedList(self::arrayField($payload,'post_global')),preGlobalTags:self::stringList(self::arrayField($payload,'pre_global_tags')),postGlobalTags:self::stringList(self::arrayField($payload,'post_global_tags')),hasDomainRoutes:$hasDomainRoutes,environment:$environment,configFingerprint:$configFingerprint,artifactFingerprint:$artifactFingerprint);
    }

    /** Artifact fingerprint was calculated and verified from the raw encoded payload before this object was constructed. */
    public function calculatedFingerprint(): string
    {
        return $this->artifactFingerprint;
    }

    public function planFor(CompiledRoute $route): ExecutionPlan { return $this->planForIndex($route->getIndex()); }
    public function planForIndex(int $routeIndex): ExecutionPlan { return $this->plansByIndex[$routeIndex]??throw new UnexpectedValueException('Matched route index has no compiled execution plan.'); }

    /** @param array<array-key,mixed> $payload @return array<string,array{string,string|null}> */
    private static function aliases(array $payload): array
    {
        $aliases=[];foreach($payload as $name=>$tuple){if(!is_string($name)||$name===''||!is_array($tuple)||!is_string($tuple[0]??null)){throw new UnexpectedValueException('Invalid alias index in Webrick router artifact.');}$domain=$tuple[1]??null;if($domain!==null&&!is_string($domain)){throw new UnexpectedValueException('Invalid alias domain in Webrick router artifact.');}$aliases[$name]=[$tuple[0],$domain];}return $aliases;
    }
    /** @param array<string,mixed> $payload @return array<array-key,mixed> */
    private static function arrayField(array $payload,string $key): array { $value=$payload[$key]??null;if(!is_array($value)){throw new UnexpectedValueException("Malformed Webrick router artifact field '{$key}'.");}return $value; }
    /** @param array<array-key,mixed> $payload @return list<mixed> */
    private static function decodedList(array $payload): array { return array_map(ArtifactValueCodec::decode(...),array_values($payload)); }
    /** @param array<string,mixed> $payload @return array{bool,string,string,string} */
    private static function header(array $payload): array
    {
        if(($payload['format']??null)!==self::FORMAT_VERSION){throw new UnexpectedValueException('Unsupported Webrick router artifact format.');}
        foreach(['environment','config_fingerprint','artifact_fingerprint'] as $key){if(!is_string($payload[$key]??null)||$payload[$key]===''){throw new UnexpectedValueException("Malformed Webrick router artifact field '{$key}'.");}}
        if(!is_bool($payload['has_domain_routes']??null)){throw new UnexpectedValueException('Malformed Webrick domain-routing capability.');}
        return [$payload['has_domain_routes'],$payload['environment'],$payload['config_fingerprint'],$payload['artifact_fingerprint']];
    }
    /** @param array<array-key,mixed> $payload @return list<string> */
    private static function stringList(array $payload): array { $values=[];foreach($payload as $value){if(!is_string($value)||$value===''){throw new UnexpectedValueException('Invalid string list in Webrick router artifact.');}$values[]=$value;}return $values; }
}
