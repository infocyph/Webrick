<?php

namespace Infocyph\Webrick\Middleware;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Skip expensive body-parsing middleware unless a form payload is present.
 *
 * Cheap heuristics:
 *   • Content-Length   > 0
 *   • Content-Type     starts with application/x-www-form-urlencoded
 *                       - or - multipart/form-data
 *   • Method           is POST  (override + CSRF only make sense here)
 */
final class GuardEmptyPost
{
    public function __invoke(Request $req, callable $next): Response
    {
        // ------ extremely cheap header / method check --------------------
        if (
            $req->getMethod() !== 'POST' ||
            ($req->getHeaderLine('content-length') === '' || (int)$req->getHeaderLine('content-length') === 0) ||
            !$this->isForm($req->getHeaderLine('content-type'))
        ) {
            // No form payload → jump straight past method-override / CSRF
            return $next($req);
        }

        // Form payload present → let the heavy middleware do their job
        return $next($req);
    }

    private function isForm(string $ctype): bool
    {
        // strip parameters like "; charset=UTF-8"
        $mime = strtolower(strtok($ctype, ';') ?: '');
        return $mime === 'application/x-www-form-urlencoded'
            || $mime === 'multipart/form-data';
    }
}
