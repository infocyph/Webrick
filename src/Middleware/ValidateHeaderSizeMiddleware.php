<?php

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class ValidateHeaderSizeMiddleware
{
    public function __construct(private int $maxBytes = 8192) {}

    public function __invoke(Request $r, Closure $next): Response
    {
        $len = array_sum(array_map('strlen', $r->getHeaders()));
        return $len > $this->maxBytes
            ? new Response(431, new Stream('Request headers too large'), [])
            : $next($r);
    }
}
