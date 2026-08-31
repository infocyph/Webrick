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

    public static function fromRequest(Request $request): ?RequestContext
    {
        $context = $request->getAttribute(RequestContext::ATTRIBUTE);

        return $context instanceof RequestContext ? $context : null;
    }

    /**
     * Compatibility constructor for callers that explicitly retain the context.
     * @param Request $request
     * @param bool $otelAvailable
     */
    public static function initialize(Request $request, bool $otelAvailable = false): RequestContext
    {
        return new RequestContext($request, $otelAvailable);
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
