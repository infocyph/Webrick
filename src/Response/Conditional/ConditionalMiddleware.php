<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Conditional;

use Closure;
use Infocyph\Webrick\Response\Response;
use Psr\Http\Message\ServerRequestInterface;

final class ConditionalMiddleware
{
    /**
     * @param Closure(ServerRequestInterface): array{string|null,int|null} $meta
     */
    public function __construct(private readonly Closure $meta)
    {
    }

    public function __invoke(ServerRequestInterface $req, Closure $next): Response
    {
        /* -------- entity metadata ----------------------------------- */
        [$etag, $lm] = ($this->meta)($req);
        $validator   = new ConditionalValidator($etag, $lm);
        $out         = $validator->evaluate($req);

        /* --- short-circuit for 304 / 412 ---------------------------- */
        if ($out->state !== Outcome::PASS) {
            return Response::empty($out->http, $out->headers);
        }

        /* -------- Range / If-Range handling ------------------------- */
        if ($req->hasHeader('Range')) {
            if (!$validator->isRangeFresh($req)) {
                // Range is stale → strip it so controller emits full body (200)
                $req = $req->withoutHeader('Range');
            }
            // If Range is fresh we *leave it*, expecting a later
            // RangeResponder middleware or the controller itself to deal with it.
        }

        /* ---------- pass to downstream handler ---------------------- */
        $resp = $next($req);

        /* echo ETag / Last-Modified if still missing ----------------- */
        foreach ($out->headers as $h => $v) {
            if (!$resp->hasHeader($h)) {
                $resp = $resp->withHeader($h, $v);
            }
        }
        return $resp;
    }
}
