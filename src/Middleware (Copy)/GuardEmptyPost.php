<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Skip expensive body-parsing middleware only when a form body is
 * certainly absent.  Handles both Content-Length *and* chunked TE.
 */
final class GuardEmptyPost
{
    public function __invoke(Request $req, callable $next): Response
    {
        /* 1 – cheap predicates that GUARANTEE there is **no** form body */
        if (
            $req->getMethod() !== 'POST' ||
            !$this->isForm($req->getHeaderLine('content-type')) ||
            $this->isExplicitlyEmpty($req)
        ) {
            // fall straight through – no MethodOverride / CSRF parsing needed
            return $next($req);
        }

        /* 2 – body might be present (len > 0 or chunked); keep heavy middleware */
        return $next($req);
    }

    /* --------------------------------------------------------------------- */

    private function isForm(string $ctype): bool
    {
        // strip parameters like "; charset=UTF-8"
        $mime = strtolower(strtok($ctype, ';') ?: '');
        return $mime === 'application/x-www-form-urlencoded'
            || $mime === 'multipart/form-data';
    }

    /**
     * True when we can assert the body is empty:
     *   • `Content-Length: 0`
     *   • *or* both CL & TE are absent (HTTP/1.0 style)
     */
    private function isExplicitlyEmpty(Request $req): bool
    {
        $cl = trim($req->getHeaderLine('content-length'));
        if ($cl !== '') {
            return (int)$cl === 0;                           // CL present + zero
        }

        // No Content-Length – check Transfer-Encoding
        $te = strtolower($req->getHeaderLine('transfer-encoding'));

        // Any TE other than "identity" means a body will stream in (chunked, gzip…)
        return $te === '' || $te === 'identity';
    }
}
