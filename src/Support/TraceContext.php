<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Support;

use Infocyph\Webrick\Request\Request;
use LogicException;

/**
 * Stateless helpers for explicit request-local tracing context.
 *
 * Webrick 5 deliberately has no process-global "current request" accessor.
 */
final class TraceContext
{
    private function __construct() {}

    public static function attach(Request $request, bool $otelAvailable = false): Request
    {
        $context = new RequestContext($request, $otelAvailable);

        return $request->withAttribute(RequestContext::ATTRIBUTE, $context);
    }

    /**
     * Compatibility entry point for existing middleware. It no longer mutates
     * global state; callers that need ambient data must retain the returned
     * RequestContext or attach it to the Request explicitly.
     */
    public static function initialize(Request $request, bool $otelAvailable = false): RequestContext
    {
        return new RequestContext($request, $otelAvailable);
    }

    /**
     * Compatibility no-op: there is no process-global context to clear.
     */
    public static function clear(): void {}

    public static function fromRequest(Request $request): ?RequestContext
    {
        $context = $request->getAttribute(RequestContext::ATTRIBUTE);

        return $context instanceof RequestContext ? $context : null;
    }

    public static function require(Request $request): RequestContext
    {
        $context = self::fromRequest($request);
        if (!$context instanceof RequestContext) {
            throw new LogicException('Request does not contain a Webrick RequestContext.');
        }

        return $context;
    }
}
