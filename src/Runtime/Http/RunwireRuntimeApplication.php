<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Metrics\RuntimeMetricsSnapshot;
use Infocyph\Runwire\Runtime\AdmissionPolicy;
use Infocyph\Runwire\Runtime\ApplicationLifecycleHooks;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Host\RuntimeApplication;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;

/** Delegates lifecycle ownership to Runwire while Webrick owns response body production. */
final readonly class RunwireRuntimeApplication implements RuntimeApplicationInterface
{
    private RuntimeApplication $application;

    /**
     * @param callable(HttpRequest, ResponseWriterInterface): void $handler
     * @param callable(): void|null $requestCleanup
     * @param callable(): void|null $shutdown
     */
    public function __construct(
        callable $handler,
        RuntimeContext $runtimeContext,
        ?callable $requestCleanup = null,
        ?callable $shutdown = null,
        ?RequestExecutionPolicy $requestExecution = null,
        ?ApplicationLifecycleHooks $lifecycle = null,
        ?AdmissionPolicy $admission = null,
    ) {
        $this->application = new RuntimeApplication(
            $handler,
            $requestCleanup,
            $shutdown,
            $runtimeContext,
            $requestExecution,
            $lifecycle,
            $admission,
        );
    }

    public function cancelActive(CancellationReason $reason = CancellationReason::HOST_CANCELLED): void
    {
        $this->application->cancelActive($reason);
    }

    public function drain(ShutdownReason $reason = ShutdownReason::SUPERVISOR_STOP): void
    {
        $this->application->drain($reason);
    }

    public function handle(
        HttpRequest $request,
        ResponseWriterInterface $writer,
        bool $completeResponse = false,
    ): void {
        unset($completeResponse);
        RunwireResponseContinuation::run(
            function () use ($request, $writer): void {
                $this->application->handle($request, $writer, false);
            },
        );
    }

    public function shutdown(?ShutdownReason $reason = null): void
    {
        $this->application->shutdown($reason);
    }

    public function snapshot(): RuntimeMetricsSnapshot
    {
        return $this->application->snapshot();
    }

    public function start(): void
    {
        $this->application->start();
    }
}
