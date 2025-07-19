<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class CompressionMiddleware
{
    private const MIN_SIZE = 1024;

    /** MIME prefixes we never compress */
    private const NO_COMPRESS_PREFIXES = [
        'image/',
        'video/',
        'audio/',
        'application/zip',
        'application/gzip',
        'application/octet-stream',
    ];

    /** encoder → callable (only invoked when extension is loaded) */
    private const ALGO = [
        'br'   => 'brotli_compress',
        'zstd' => 'zstd_compress',
        'gzip' => 'gzencode',
    ];

    /* ─────────────────────────────────────────────────────────────── */

    public function __invoke(Request $req, Closure $next): Response
    {
        $resp = $next($req);

        /* -------- quick exits -------------------------------------- */
        if ($resp->hasHeader('Content-Encoding')) {
            return $resp;                       // already encoded
        }

        $contentLength = $resp->getHeaderLine('Content-Length');
        if (
            ($contentLength !== '' && (int) $contentLength < self::MIN_SIZE) ||
            ($contentLength === ''  && ($resp->getBody()->getSize() ?? 0) < self::MIN_SIZE)
        ) {
            return $resp;                       // payload too small
        }

        $ctype = strtolower($resp->getHeaderLine('Content-Type'));
        if ($ctype !== '' && $this->isNonCompressible($ctype)) {
            return $resp;                       // binary / already-compressed
        }

        /* -------- pick best algorithm ----------------------------- */
        $alg = $this->negotiate($req->getHeaderLine('Accept-Encoding'));
        if ($alg === null) {
            return $resp;                       // client accepts none
        }

        /* -------- encode ------------------------------------------ */
        $raw = (string) $resp->getBody();
        $enc = ($alg === 'gzip')
            ? gzencode($raw, 6, ZLIB_ENCODING_GZIP)
            : \call_user_func(self::ALGO[$alg], $raw);

        if ($enc === false) {                   // encoder failed – extremely rare
            return $resp;
        }

        return $resp
            ->withBody(new Stream($enc))
            ->withHeader('Content-Encoding', $alg)
            ->withHeader('Content-Length', (string) \strlen($enc))
            ->withHeader('Vary', 'Accept-Encoding');         // caching safety
    }

    /* ───────────────────────── helpers ──────────────────────────── */

    /** True when `$ctype` starts with any of the NO_COMPRESS prefixes. */
    private function isNonCompressible(string $ctype): bool
    {
        return array_any(self::NO_COMPRESS_PREFIXES, fn ($prefix) => \str_starts_with($ctype, $prefix));
    }

    /**
     * Return the best supported encoding from Accept-Encoding or **null**.
     *
     * Broken down into two small helpers to keep cyclomatic complexity low.
     */
    private function negotiate(string $header): ?string
    {
        $candidates = $this->parseAcceptHeader($header);

        foreach ($candidates as $alg) {
            if (isset(self::ALGO[$alg]) && \function_exists(self::ALGO[$alg])) {
                return $alg;                                 // direct hit
            }
            if ($alg === '*') {                              // wildcard ⇒ pick first available
                foreach (['br', 'zstd', 'gzip'] as $fallback) {
                    if (isset(self::ALGO[$fallback]) && \function_exists(self::ALGO[$fallback])) {
                        return $fallback;
                    }
                }
            }
        }
        return null;
    }

    /** Parse and sort “br;q=1.0, gzip;q=0.8” → ordered list ['br','gzip',…] */
    private function parseAcceptHeader(string $header): array
    {
        if ($header === '') {
            return [];
        }

        $parsed = [];
        foreach (\explode(',', $header) as $seg) {
            if ($seg === '') {
                continue;
            }
            [$token, $q] = \array_map('trim', \explode(';', $seg, 2) + [1 => 'q=1']);
            $qVal = (float) (\preg_match('/q=([\d.]+)/', $q, $m) ? $m[1] : 1);
            $parsed[] = [\strtolower($token), $qVal];
        }

        \usort(
            $parsed,
            static fn (array $a, array $b): int => $b[1] <=> $a[1]   // highest q first
        );

        return \array_column($parsed, 0);
    }
}
