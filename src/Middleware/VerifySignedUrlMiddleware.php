<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Url\Signature;
use Infocyph\Webrick\Router\Url\SignedUrlGenerator;

final readonly class VerifySignedUrlMiddleware
{
    public function __construct(
        private string $secret,
        private int|string $leeway = 0,
        private bool $verbose = false,
    ) {
    }

    public function __invoke(Request $request, Closure $next)
    {
        // 1) pull query and signature, then remove _sig
        $qs  = $request->getQueryParams();
        $sig = $qs[SignedUrlGenerator::SIG_PARAM] ?? '';
        if (!is_string($sig) || $sig === '') {
            return Response::plaintext('Missing signature', 400);
        }
        unset($qs[SignedUrlGenerator::SIG_PARAM]);

        // 2) expiry check (if present)
        if (isset($qs[SignedUrlGenerator::EXPIRES_PARAM])) {
            $exp = (int) $qs[SignedUrlGenerator::EXPIRES_PARAM];
            if (time() > $exp + $this->leeway) {
                return Response::plaintext('URL expired', 410);
            }
        }

        // 3) canonicalize EXACTLY like the generator
        ksort($qs); // same top-level sort as generator
        $path = $request->getUri()->getPath();
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/'); // ensure leading slash
        }

        $payload = $path;
        if ($qs !== []) {
            $payload .= '?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
        }

        // 4) constant-time compare
        if (!Signature::check($payload, $sig, $this->secret)) {
            if ($this->verbose) {
                return Response::json([
                    'error'   => 'Bad signature',
                    'payload' => $payload,
                    'sig'     => $sig,
                ], 400);
            }
            return Response::plaintext('Bad signature', 400);
        }

        return $next($request);
    }
}
