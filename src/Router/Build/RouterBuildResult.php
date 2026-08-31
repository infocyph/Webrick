<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Build;

use Infocyph\Webrick\Router\Route\CompiledCollection;

final readonly class RouterBuildResult
{
    /**
     * @param array<string,ExecutionPlan> $plans
     * @param array<string,array{0:string,1:?string}> $aliases
     * @param list<mixed> $preGlobal
     * @param list<mixed> $postGlobal
     * @param list<string> $preGlobalTags
     * @param list<string> $postGlobalTags
     */
    public function __construct(
        public CompiledCollection $routes,
        public array $plans,
        public array $aliases,
        public array $preGlobal,
        public array $postGlobal,
        public array $preGlobalTags,
        public array $postGlobalTags,
        public bool $hasDomainRoutes,
        public string $environment,
        public string $configFingerprint,
    ) {}
}
