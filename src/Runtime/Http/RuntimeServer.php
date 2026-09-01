<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Kernel\CompiledRouterKernel;

/** Runs one boot-selected runtime adapter without per-request engine discovery. */
final readonly class RuntimeServer
{
    public function __construct(
        private CompiledRouterKernel $kernel,
        private RuntimeAdapterInterface $adapter,
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
        $response = $this->kernel->handleRuntime($context);
        $this->adapter->write($response, $context);

        return $response;
    }
}
