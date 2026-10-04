<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Closure;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\Integration\Runwire\RunwireIntegration;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;
use LogicException;
use RuntimeException;
use UnexpectedValueException;

/** Optional host-owned Runwire/InterMix binding metadata. */
final readonly class RunwireRuntimeBinding
{
    /** @var Closure(HttpRequest): mixed|null */
    private ?Closure $coroutineScopeResolver;

    private ?RunwireIntegration $interMix;

    private ?RuntimeContext $runtimeContext;

    /** @var Closure(HttpRequest): mixed|null */
    private ?Closure $scopeContextResolver;

    /**
     * @param callable(HttpRequest): mixed|null $coroutineScope
     * @param callable(HttpRequest): mixed|null $scopeContext
     */
    public function __construct(
        ?RuntimeContext $runtimeContext = null,
        ?RunwireIntegration $interMix = null,
        ?callable $coroutineScope = null,
        ?callable $scopeContext = null,
    ) {
        $boundRuntime = $interMix?->runtime();
        if ($runtimeContext !== null && $boundRuntime !== null && $boundRuntime !== $runtimeContext) {
            throw new LogicException('Runwire adapter and InterMix integration are bound to different runtimes.');
        }
        if ($interMix !== null && !$boundRuntime instanceof RuntimeContext) {
            throw new LogicException('InterMix Runwire integration must be bound before adapter construction.');
        }

        $this->interMix = $interMix;
        $this->runtimeContext = $runtimeContext ?? $boundRuntime;
        $this->coroutineScopeResolver = $coroutineScope === null ? null : Closure::fromCallable($coroutineScope);
        $this->scopeContextResolver = $scopeContext === null ? null : Closure::fromCallable($scopeContext);
    }

    public function assertRequest(RequestContext $request): void
    {
        if ($this->runtimeContext !== null && $request->runtime() !== $this->runtimeContext) {
            throw new RuntimeException('Runwire request context is bound to a different runtime.');
        }
    }

    public function runtime(): ?RuntimeContext
    {
        return $this->runtimeContext;
    }

    public function scopeBridge(HttpRequest $request): ?RunwireInterMixScopeBridge
    {
        if ($this->interMix === null) {
            return null;
        }

        return new RunwireInterMixScopeBridge(
            $this->interMix,
            $request->context,
            $this->resolveCoroutineScope($request),
            $this->resolveScopeContext($request),
        );
    }

    private function resolveCoroutineScope(HttpRequest $request): ?CoroutineScope
    {
        if ($this->coroutineScopeResolver === null) {
            return null;
        }

        $scope = ($this->coroutineScopeResolver)($request);
        if ($scope === null || $scope instanceof CoroutineScope) {
            return $scope;
        }

        throw new UnexpectedValueException('Runwire coroutine-scope resolver must return CoroutineScope or null.');
    }

    private function resolveScopeContext(HttpRequest $request): ?ScopeContext
    {
        if ($this->scopeContextResolver === null) {
            return null;
        }

        $context = ($this->scopeContextResolver)($request);
        if ($context === null || $context instanceof ScopeContext) {
            return $context;
        }

        throw new UnexpectedValueException('Runwire scope-context resolver must return ScopeContext or null.');
    }
}
