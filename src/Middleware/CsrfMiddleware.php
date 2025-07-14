<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class CsrfMiddleware
{
    /** Public flag (can appear in a request) */
    public const BYPASS_ATTR   = '_csrf_bypass';
    /** Private sentinel – never comes from the network */
    private const TRUST_MARKER = '__csrf_internal__';

    /**
     * Mark a Request object as **trusted** (e.g. in tests, job replay, etc.).
     * Down-stream code can now attach BYPASS_ATTR safely.
     */
    public static function markTrusted(Request $r): Request
    {
        return $r->withAttribute(self::TRUST_MARKER, true);
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        /*────── secure bypass ──────────────────────────────────────────*/
        if (
            $req->getAttribute(self::TRUST_MARKER, false) === true &&
            $req->getAttribute(self::BYPASS_ATTR, false) === true
        ) {
            return $next($req);                       // ✅ internal bypass
        }

        /*────── normal flow ───────────────────────────────────────────*/
        if (!\in_array($req->getMethod(), ['POST','PUT','PATCH','DELETE'], true)) {
            return $next($req);                      // safe verbs
        }

        if (!$req->matchesCsrfToken()) {
            return new Response(
                status  : 419,
                headers : ['Content-Type' => 'text/plain; charset=utf-8'],
                body    : new Stream('CSRF token mismatch.')
            );
        }

        return $next($req);
    }
}
