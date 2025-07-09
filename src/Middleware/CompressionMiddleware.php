<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Content-encoding negotiation that supports **gzip**, **br** (Brotli)
 * and **zstd** when the corresponding PHP extension is loaded.
 *
 * – Skips tiny payloads (< 1 KiB) and already-compressed media-types.
 * – Always sets **Vary: Accept-Encoding**.
 */
final readonly class CompressionMiddleware
{
    /** Don’t bother below this size (bytes). */
    private const MIN_SIZE = 1024;

    /** Content-types we NEVER compress. */
    private const NO_COMPRESS_RX =
        '#^(?:image/|video/|audio/|application/(?:zip|gzip|octet-stream))#i';

    /** Map enc => callable */
    private const ALGO = [
        'br'   => 'brotli_compress',
        'zstd' => 'zstd_compress',
        'gzip' => 'gzencode',
    ];

    public function __invoke(Request $req, Closure $next): Response
    {
        $resp = $next($req);

        /* --- fast bail-outs ------------------------------------------------ */
        if ($resp->hasHeader('Content-Encoding')) {
            return $resp;
        }
        if ($resp->getBody()->getSize() !== null
            && $resp->getBody()->getSize() < self::MIN_SIZE
        ) {
            return $resp;
        }
        if (preg_match(self::NO_COMPRESS_RX, $resp->getHeaderLine('Content-Type'))) {
            return $resp;                                    // already compressed
        }

        /* --- negotiation --------------------------------------------------- */
        $alg = $this->chooseAlgo($req->getHeaderLine('Accept-Encoding'));
        if ($alg === null) {
            return $resp;                                    // client accepts none
        }

        $raw = (string) $resp->getBody();
        $enc = ($alg === 'gzip')
            ? gzencode($raw, 6, ZLIB_ENCODING_GZIP)
            : \call_user_func(self::ALGO[$alg], $raw);

        if ($enc === false) {
            return $resp;                                    // very unlikely
        }

        return $resp
            ->withBody(new Stream($enc))
            ->withHeader('Content-Encoding', $alg)
            ->withHeader('Content-Length', (string) strlen($enc))
            ->withHeader('Vary', 'Accept-Encoding');
    }

    private function chooseAlgo(string $accept): ?string
    {
        /* Parse “br;q=1.0, gzip;q=0.8” → [['br',1.0], ['gzip',0.8]] */
        $cands = [];
        foreach (explode(',', $accept) as $seg) {
            if ($seg === '') { continue; }
            [$token, $q] = array_map('trim', explode(';', $seg, 2) + [1 => 'q=1']);
            $q = (float) (preg_match('/q=([\d.]+)/', $q, $m) ? $m[1] : 1);
            $cands[] = [strtolower($token), $q];
        }
        usort($cands, fn($a, $b) => $b[1] <=> $a[1]);

        foreach ($cands as [$enc]) {
            if (isset(self::ALGO[$enc]) && \function_exists(self::ALGO[$enc])) {
                return $enc;
            }
            if ($enc === '*' ) {            // wildcard, pick best available
                foreach (['br','zstd','gzip'] as $fallback) {
                    if (isset(self::ALGO[$fallback]) && \function_exists(self::ALGO[$fallback])) {
                        return $fallback;
                    }
                }
            }
        }
        return null;
    }
}
