<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use RuntimeException;

/**
 * Request-local runtime state.
 *
 * Native request/response handles stay here and are never copied into process
 * globals or hidden inside Request attributes. Full Request materialization is
 * cached and occurs only when the selected execution plan requires it.
 */
final class RuntimeRequestContext
{
    private ?Request $request = null;

    /**
     * @param Closure():Request $requestFactory
     */
    public function __construct(
        public readonly RoutingInput $routing,
        private readonly Closure $requestFactory,
        public readonly RuntimeCapabilities $capabilities,
        public readonly mixed $nativeRequest = null,
        public readonly mixed $nativeResponse = null,
    ) {}

    public function request(): Request
    {
        if ($this->request !== null) {
            return $this->request;
        }

        $request = ($this->requestFactory)();
        if (!$request instanceof Request) {
            throw new RuntimeException('Runtime request factory must return Request.');
        }

        return $this->request = $request->withAttribute(RuntimeCapabilities::ATTRIBUTE, $this->capabilities);
    }

    public function scopeId(): string
    {
        return 'webrick.request.' . spl_object_id($this);
    }
}
