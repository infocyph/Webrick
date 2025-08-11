<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Conditional\ConditionalValidator;
use Infocyph\Webrick\Response\Conditional\Outcome;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;

/**
 * Pre-flight validator for ETag / Last-Modified / Range handling.
 *
 * The callable you pass in **must** return `[etag|null, lastModified|null]`
 * for the current entity. Everything else is taken care of.
 */
final readonly class ConditionalMiddleware
{
    /**
     * @param Closure(Request): array{string|null,int|null} $meta
     */
    public function __construct(private Closure $meta) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        /* ---------- entity metadata ----------------------------------- */
        [$etag, $lm] = ($this->meta)($req);
        $validator = new ConditionalValidator($etag, $lm);
        $result = $validator->evaluate($req);

        /* ---------- 304 / 412 short-circuit --------------------------- */
        if ($result->state !== Outcome::PASS) {
            return Response::empty($result->http, $result->headers);
        }

        /* ---------- stale Range? strip so downstream sends 200 -------- */
        if ($req->hasHeader('Range') && !$validator->isRangeFresh($req)) {
            $req = $req
                ->withoutHeader('Range')
                ->withAttribute('range_dropped', true);
        }

        /* ---------- downstream --------------------------------------- */
        $resp = $next($req);

        /* ---------- add ETag / Last-Modified if controller forgot ----- */
        foreach ($result->headers as $h => $v) {
            if (!$resp->hasHeader($h)) {
                $resp = $resp->withHeader($h, $v);
            }
        }

        return $resp;
    }
}
