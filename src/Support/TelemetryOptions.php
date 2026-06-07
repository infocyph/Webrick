<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class TelemetryOptions
{
    public function __construct(
        public LoggerInterface $log = new NullLogger(),
        public bool $addXResponseTime = true,
        public bool $addServerTiming = true,
        public bool $emitRequestId = true,
        public string $requestIdHeader = 'X-Request-Id',
        public bool $respectExistingRequestId = true,
        public ?string $nelGroup = null,
        public ?string $nelEndpoint = null,
        public int $nelTtlSeconds = 86400,
        public bool $nelIncludeSubdomains = true,
        public bool $nelCollectSuccesses = false,
        public bool $emitTraceIdHeader = true,
        public string $traceIdHeader = 'Trace-Id',
        public bool $respectIncomingTraceparent = true,
        public bool $emitTraceparentHeader = false,
        public bool $enableOtelIntegration = false,
        public string $otelServiceName = 'webrick-app',
        public string $otelServiceVersion = '1.0.0',
    ) {}

    /**
     * @return array{
     *   0:LoggerInterface,
     *   1:bool,
     *   2:bool,
     *   3:bool,
     *   4:string,
     *   5:bool,
     *   6:?string,
     *   7:?string,
     *   8:int,
     *   9:bool,
     *   10:bool,
     *   11:bool,
     *   12:string,
     *   13:bool,
     *   14:bool,
     *   15:bool,
     *   16:string,
     *   17:string
     * }
     */
    public function toMiddlewareConstructorArgs(): array
    {
        return [
            $this->log,
            $this->addXResponseTime,
            $this->addServerTiming,
            $this->emitRequestId,
            $this->requestIdHeader,
            $this->respectExistingRequestId,
            $this->nelGroup,
            $this->nelEndpoint,
            $this->nelTtlSeconds,
            $this->nelIncludeSubdomains,
            $this->nelCollectSuccesses,
            $this->emitTraceIdHeader,
            $this->traceIdHeader,
            $this->respectIncomingTraceparent,
            $this->emitTraceparentHeader,
            $this->enableOtelIntegration,
            $this->otelServiceName,
            $this->otelServiceVersion,
        ];
    }
}
