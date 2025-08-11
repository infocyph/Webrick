<?php

namespace Infocyph\Webrick\Middleware;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Negotiation\CharsetNegotiator;
use Infocyph\Webrick\Response\Response;

final class CharsetAttachMiddleware
{
    public function __construct(private array $supported = ['utf-8', 'iso-8859-1']) {}

    public function __invoke(Request $req, \Closure $next): Response
    {
        $resp = $next($req);

        $ctype = $resp->getHeaderLine('Content-Type');
        if ($ctype === '' || stripos($ctype, 'charset=') !== false) {
            return $resp;
        }
        if (!preg_match('#^(text/|application/(?:xml|xhtml\+xml))#i', $ctype)) {
            return $resp;
        }
        if (stripos($ctype, 'application/json') === 0) {
            return $resp;
        } // be explicit

        $chosen = CharsetNegotiator::choose(
            $this->supported,
            $req->getHeaderLine('Accept-Charset'),
        ) ?? $this->supported[0];

        return $resp
            ->withHeader('Content-Type', $ctype . '; charset=' . $chosen)
            ->withSmartHeader('Vary', 'Accept-Charset');
    }
}
