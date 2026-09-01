<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;
use Infocyph\Webrick\Router\Runtime\RuntimeStageProfiler;

/** Runs one boot-selected runtime adapter without per-request engine discovery. */
final readonly class RuntimeServer
{
    public function __construct(
        private CompiledRouterKernel $kernel,
        private RuntimeAdapterInterface $adapter,
        private ?RuntimeStageProfiler $profiler = null,
    ) {}

    public function capabilities(): RuntimeCapabilities
    {
        return $this->adapter->capabilities();
    }

    public function handle(mixed $nativeRequest = null, mixed $nativeResponse = null): Response
    {
        $context = $this->adapter->context(
            $nativeRequest,
            $nativeResponse,
            $this->kernel->requiresHostRouting(),
        );
        $this->profiler?->mark('runtime_context');

        $response = $this->kernel->handleRuntime($context);
        $this->adapter->write($response, $context);
        $this->profiler?->mark('response_write');

        return $response;
    }
}
