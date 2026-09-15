<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Closure;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Runtime\AdmissionPolicy;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\Runtime\RuntimeApplicationFactoryInterface;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\RuntimeContext;

/** Creates one Webrick application bridge for the Runwire-selected runtime context. */
final readonly class RunwireRuntimeApplicationFactory implements RuntimeApplicationFactoryInterface
{
    /** @var Closure(HttpRequest, ResponseWriterInterface): void */
    private Closure $handler;

    /** @var Closure(): void|null */
    private ?Closure $requestCleanup;

    /** @var Closure(): void|null */
    private ?Closure $shutdown;

    /**
     * @param callable(HttpRequest, ResponseWriterInterface): void $handler
     * @param callable(): void|null $requestCleanup
     * @param callable(): void|null $shutdown
     */
    public function __construct(
        callable $handler,
        ?callable $requestCleanup = null,
        ?callable $shutdown = null,
        private ?RequestExecutionPolicy $requestExecution = null,
        private ?ApplicationLifecycleHooks $lifecycle = null,
        private ?AdmissionPolicy $admission = null,
    ) {
        $this->handler = Closure::fromCallable($handler);
        $this->requestCleanup = $requestCleanup === null ? null : Closure::fromCallable($requestCleanup);
        $this->shutdown = $shutdown === null ? null : Closure::fromCallable($shutdown);
    }

    public function create(RuntimeContext $context): RuntimeApplicationInterface
    {
        return new RunwireRuntimeApplication(
            $this->handler,
            $context,
            $this->requestCleanup,
            $this->shutdown,
            $this->requestExecution,
            $this->lifecycle,
            $this->admission,
        );
    }
}
