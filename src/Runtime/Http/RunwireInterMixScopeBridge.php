<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\InterMix\Integration\Runwire\RunwireIntegration;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Runtime\InterMixRuntime;
use LogicException;

/**
 * Adapts an already-bound InterMix Runwire integration to Webrick dispatch.
 *
 * Runtime, request, coroutine and borrowed DI-scope ownership remains with the
 * host. Webrick neither creates nor releases those objects here.
 */
final readonly class RunwireInterMixScopeBridge implements RuntimeScopeBridgeInterface
{
    public function __construct(
        private RunwireIntegration $integration,
        private RequestContext $requestContext,
        private ?CoroutineScope $coroutineScope = null,
        private ?ScopeContext $scopeContext = null,
    ) {}

    public static function registerInputs(ContainerBuilder $builder): ContainerBuilder
    {
        return RunwireIntegration::registerInputs($builder);
    }

    public function withinScope(
        InterMixRuntime $runtime,
        ?Request $request,
        callable $callback,
    ): mixed {
        $boundRuntime = $this->integration->runtime();
        if (!$boundRuntime instanceof RuntimeContext) {
            throw new LogicException('InterMix Runwire integration must be bound before Webrick dispatch.');
        }
        if ($this->requestContext->runtime() !== $boundRuntime) {
            throw new LogicException('Webrick received a Runwire request bound to a different runtime.');
        }

        $this->integration->checkpoint($this->coroutineScope);
        $invoke = static function (RuntimeContainerInterface $container) use ($runtime, $callback): mixed {
            if ($container !== $runtime->container()) {
                throw new LogicException('InterMix Runwire integration belongs to a different container.');
            }

            return $callback();
        };

        if ($this->scopeContext instanceof ScopeContext) {
            return $this->integration->withinScopeContext(
                $this->scopeContext,
                $this->requestContext,
                $this->coroutineScope,
                $invoke,
            );
        }

        return $this->integration->withinRequest(
            $this->requestContext,
            $this->coroutineScope,
            $invoke,
            $request instanceof Request ? [Request::class => $request] : [],
        );
    }
}
