<?php

// src/Middleware/VaryAccumulatorMiddleware.php
declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Headers\Vary;

final class VaryAccumulatorMiddleware
{
    private const ATTR = '__vary_builder';

    /** Middlewares/controllers can register tokens any time */
    public static function add(Request $r, string ...$headers): Request
    {
        $v = $r->getAttribute(self::ATTR);
        $v = $v instanceof Vary ? $v->add(...$headers) : Vary::new()->add(...$headers);
        return $r->withAttribute(self::ATTR, $v);
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        $resp = $next($req);

        // Start from any Vary already set by downstream
        $final = ($existing = $resp->getHeaderLine('Vary')) !== ''
            ? Vary::fromString($existing)
            : Vary::new();

        // Merge tokens explicitly registered on the request
        if (($builder = $req->getAttribute(self::ATTR)) instanceof Vary) {
            $final = $final->add(...array_map('trim', explode(',', (string)$builder)));
        }

        // Auto-infer common dependencies from the final response
        if ($resp->hasHeader('Content-Encoding')) {
            $final = $final->add('Accept-Encoding');
        }
        if ($resp->hasHeader('Content-Language')) {
            $final = $final->add('Accept-Language');
        }
        // If CORS reflected an origin (i.e., not "*"), vary by Origin
        $acao = $resp->getHeaderLine('Access-Control-Allow-Origin');
        if ($acao !== '' && $acao !== '*') {
            $final = $final->add('Origin');
        }

        $line = (string)$final;
        return $line !== '' ? $resp->withHeader('Vary', $line) : $resp;
    }
}
