<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Runwire\Exception\CancelledException;
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
use Throwable;

/** Delegates lifecycle ownership to Runwire while Webrick owns response body production. */
final readonly class RunwireRuntimeApplication implements RuntimeApplicationInterface
{
    private RuntimeApplication $application;

    /**
     * @param callable(HttpRequest, ResponseWriterInterface): void $handler
     * @param callable(): void|null $requestCleanup
     * @param callable(): void|null $shutdown
     * @throws \InvalidArgumentException
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

    #[\Override]
    public function cancelActive(CancellationReason $reason = CancellationReason::HOST_CANCELLED): void
    {
        $this->application->cancelActive($reason);
    }

    #[\Override]
    public function drain(ShutdownReason $reason = ShutdownReason::SUPERVISOR_STOP): void
    {
        $this->application->drain($reason);
    }

    /**
     * @throws \Infocyph\Runwire\Exception\CancelledException
     * @throws \Throwable
     */
    #[\Override]
    public function handle(
        HttpRequest $request,
        ResponseWriterInterface $writer,
        bool $completeResponse = false,
    ): void {
        RunwireResponseContinuation::run(
            function () use ($request, $writer, $completeResponse): void {
                // This catch must live in the continuation: body/drain callbacks
                // can resume the request after handle() has already returned.
                try {
                    $this->application->handle($request, $writer, $completeResponse);
                } catch (RunwireTransportCancellation) {
                    // Runwire has already finalized transport-owned cancellation.
                } catch (CancelledException $error) {
                    if ($error->reason !== CancellationReason::TRANSPORT_CANCELLED) {
                        throw $error;
                    }
                }
            },
        );
    }

    #[\Override]
    public function healthFailure(): ?Throwable
    {
        return $this->application->healthFailure();
    }

    #[\Override]
    public function healthy(): bool
    {
        return $this->application->healthy();
    }

    /**
     * @throws \Infocyph\Runwire\Exception\ApplicationShutdownException
     */
    #[\Override]
    public function shutdown(?ShutdownReason $reason = null): void
    {
        $this->application->shutdown($reason);
    }

    #[\Override]
    public function snapshot(): RuntimeMetricsSnapshot
    {
        return $this->application->snapshot();
    }

    /**
     * @throws \Infocyph\Runwire\Exception\ApplicationStartupException
     * @throws \LogicException
     */
    #[\Override]
    public function start(): void
    {
        $this->application->start();
    }
}
