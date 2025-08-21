<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\StreamUtil;

final readonly class CompressionMiddleware
{
    /* ─────────── ETag strategies ─────────── */
    public const ETAG_WEAK_ON_ENCODE = 'weak-on-encode';
    public const ETAG_STRONG_RECOMP = 'recompute-strong'; // default (bytes-on-the-wire)
    public const ETAG_STRONG_DERIVE = 'derive-strong';    // from base tag + alg/level

    /** don’t bother below this many bytes */
    public function __construct(
        private int $minBytes = 1400,
        private array $prefOrder = [['zstd', 'br', 'gzip']],
        private string $etagMode = self::ETAG_WEAK_ON_ENCODE,
        private int $gzipLevel = 6,
        private int $brotliQuality = 4,
        private int $zstdLevel = 3,
        private string $etagDeriveSalt = 'enc-v1',
        private int $maxBufferBytes = 8_388_608, // 8 MiB hard ceiling for in-memory re-encode
    ) {
    }

    /** MIME prefixes we never compress */
    private const NO_COMPRESS_PREFIXES = [
        'image/',
        'video/',
        'audio/',
        'application/zip',
        'application/gzip',
        'application/x-gzip',
        'application/x-tar',
        'application/octet-stream',
        'application/wasm',
        'text/event-stream',
    ];

    /** encoder → callable (only invoked when extension is loaded) */
    private const ALGO = [
        'br' => 'brotli_compress',   // ext-brotli
        'zstd' => 'zstd_compress',   // ext-zstd
        'gzip' => 'gzencode',        // ext-zlib (bundled)
    ];

    public function __invoke(Request $req, Closure $next): Response
    {
        $resp = $next($req);

        if (!$this->shouldCompress($req, $resp)) {
            return $resp;
        }

        $alg = $this->negotiate($req->getHeaderLine('Accept-Encoding'));
        if ($alg === null) {
            return $resp; // client accepts none (or only identity)
        }

        $raw = (string)$resp->getBody();
        $enc = $this->encode($raw, $alg);
        if ($enc === false) {
            return $resp; // encoder failed – rare
        }

        // Register Vary for negotiation (also auto-inferred by VaryAccumulator)
        VaryAccumulatorMiddleware::add($req, 'Accept-Encoding');

        $resp = $this->applyEncoded($resp, $enc, $alg);
        $resp = $this->adjustValidators($resp, $enc, $alg);

        return $resp;
    }

    /* ───────────────────────── decisions ───────────────────────── */
    private function shouldCompress(Request $req, Response $resp): bool
    {
        return match (true) {
            $resp->isStreaming(),
            in_array($resp->getStatusCode(), [204, 304, 206], true),
            strtoupper($req->getMethod()) === 'HEAD',
            $resp->hasHeader('Content-Encoding'),
            $resp->hasHeader('Content-Range'),
            $this->hasNoTransform($resp),
            (function () use ($resp): bool {
                $length = StreamUtil::byteLength($resp->getBody(), $this->minBytes);
                return $length < $this->minBytes || $length > $this->maxBufferBytes;
            })(),
            (function () use ($resp): bool {
                $contentType = strtolower(trim($resp->getHeaderLine('Content-Type')));
                return $contentType !== '' && $this->isNonCompressible($contentType);
            })() => false,
            default => true,
        };
    }

    /* ───────────────────────── encoding ───────────────────────── */

    private function encode(string $raw, string $alg): string|false
    {
        return match (true) {
            $alg === 'gzip' && \function_exists(self::ALGO['gzip']) => \gzencode(
                $raw,
                $this->gzipLevel,
                \ZLIB_ENCODING_GZIP,
            ),
            $alg === 'br' && \function_exists(self::ALGO['br']) => \brotli_compress($raw, $this->brotliQuality),
            $alg === 'zstd' && \function_exists(self::ALGO['zstd']) => \zstd_compress($raw, $this->zstdLevel),
            default => false,
        };
    }

    private function applyEncoded(Response $resp, string $enc, string $alg): Response
    {
        $resp = $resp
            ->withBody(new Stream($enc))
            ->withSmartHeader('Content-Encoding', $alg)
            ->withSmartHeader('Content-Length', (string)\strlen($enc));

        // Content-MD5 (if present) is now invalid; remove it.
        if ($resp->hasHeader('Content-MD5')) {
            $resp = $resp->withoutHeader('Content-MD5');
        }
        return $resp;
    }

    private function adjustValidators(Response $resp, string $encodedBytes, string $alg): Response
    {
        $etagLine = $resp->getHeaderLine('ETag');

        switch ($this->etagMode) {
            case self::ETAG_WEAK_ON_ENCODE:
                if ($etagLine !== '' && !str_starts_with($etagLine, 'W/')) {
                    $resp = $resp->withSmartHeader('ETag', 'W/' . $etagLine);
                } elseif ($etagLine === '') {
                    $resp = $resp->withSmartHeader('ETag', 'W/' . $this->strongFromBytes($encodedBytes));
                }
                break;

            case self::ETAG_STRONG_DERIVE:
                {
                    [$base, $isWeak] = $this->parseEtag($etagLine);

                    // No base tag at all → compute a real strong tag from the bytes we’re serving.
                    if ($base === '') {
                        return $resp->withSmartHeader('ETag', $this->strongFromBytes($encodedBytes));
                    }

                    // If base is weak, **do not** upgrade; derive a WEAK tag keyed by base+alg+level.
                    if ($isWeak) {
                        $level = $this->encodedLevelToken($alg);
                        $derived = 'W/"' . substr(
                            sha1($base . '|' . $alg . '|' . $level . '|' . $this->etagDeriveSalt),
                            0,
                            16,
                        ) . '"';
                        return $resp->withSmartHeader('ETag', $derived);
                    }

                    // Base is strong. Only safe to derive-strong when encoding is deterministic.
                    if ($this->isEncodingDeterministic($alg)) {
                        $level = $this->encodedLevelToken($alg);
                        $derived = '"' . substr(
                            sha1($base . '|' . $alg . '|' . $level . '|' . $this->etagDeriveSalt),
                            0,
                            16,
                        ) . '"';
                        return $resp->withSmartHeader('ETag', $derived);
                    }

                    // Non-deterministic encoding (e.g., gzip MTIME) → recompute on-the-wire strong tag.
                    return $resp->withSmartHeader('ETag', $this->strongFromBytes($encodedBytes));
                }

            case self::ETAG_STRONG_RECOMP:
            default:
                $resp = $resp->withSmartHeader('ETag', $this->strongFromBytes($encodedBytes));
                break;
        }

        return $resp;
    }

    /** Returns [value-without-quotes, isWeak]. Blank value means “no ETag present". */
    private function parseEtag(string $etagLine): array
    {
        $t = trim($etagLine);
        if ($t === '') {
            return ['', false];
        }
        $isWeak = str_starts_with($t, 'W/');
        if ($isWeak) {
            $t = substr($t, 2);
        }
        if (strlen($t) >= 2 && $t[0] === '"' && $t[strlen($t) - 1] === '"') {
            $t = substr($t, 1, -1);
        }
        return [trim($t), $isWeak];
    }

    /** gzip via gzencode adds an MTIME by default → bytes can vary run-to-run. */
    private function isEncodingDeterministic(string $alg): bool
    {
        // If you later make gzip deterministic (e.g., zero MTIME), flip this.
        return $alg !== 'gzip';
    }

    private function strongFromBytes(string $bytes): string
    {
        return '"' . substr(sha1($bytes), 0, 16) . '"';  // strong, short, cache-friendly
    }

    private function stripWeakQuotes(string $etagLine): string
    {
        $t = trim($etagLine);
        if ($t === '') {
            return '';
        }
        if (str_starts_with($t, 'W/')) {
            $t = substr($t, 2);
        }
        // remove optional surrounding quotes
        if (strlen($t) >= 2 && $t[0] === '"' && $t[strlen($t) - 1] === '"') {
            $t = substr($t, 1, -1);
        }
        return trim($t);
    }

    private function encodedLevelToken(string $alg): string
    {
        return match ($alg) {
            'gzip' => (string)$this->gzipLevel,
            'br' => (string)$this->brotliQuality,
            'zstd' => (string)$this->zstdLevel,
            default => '0',
        };
    }

    /* ───────────────────────── helpers ─────────────────────────── */

    private function hasNoTransform(Response $r): bool
    {
        $cc = strtolower($r->getHeaderLine('Cache-Control'));
        return $cc !== '' && str_contains($cc, 'no-transform');
    }

    /** True when `$ctype` starts with any of the NO_COMPRESS prefixes. */
    private function isNonCompressible(string $ctype): bool
    {
        return array_any(self::NO_COMPRESS_PREFIXES, fn($prefix) => \str_starts_with($ctype, $prefix));
    }

    /**
     * Return the best supported encoding from Accept-Encoding or **null**.
     */
    private function negotiate(string $header): ?string
    {
        $candidates = $this->parseAcceptHeader($header);
        if ($candidates === []) {
            // No header → HTTP/1.1 implies identity is acceptable
            return null;
        }

        foreach ($candidates as $alg) {
            if ($alg === 'identity') {
                return null; // explicit identity preference
            }

            if ($alg === '*') {
                // wildcard ⇒ pick first available in preferred order
                foreach ($this->prefOrder as $fallback) {
                    if (isset(self::ALGO[$fallback]) && \function_exists(self::ALGO[$fallback])) {
                        return $fallback;
                    }
                }
                continue;
            }

            if (isset(self::ALGO[$alg]) && \function_exists(self::ALGO[$alg])) {
                return $alg; // direct hit
            }
        }

        return null;
    }

    /** Parse and sort “br;q=1.0, gzip;q=0.8, identity;q=0” → ['br','gzip'] (filters q=0). */
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
            $qVal = (float)(\preg_match('/q=([\d.]+)/', $q, $m) ? $m[1] : 1);
            if ($qVal <= 0) {
                continue; // ignore explicitly refused codings
            }
            $parsed[] = [\strtolower($token), $qVal];
        }

        \usort(
            $parsed,
            static fn (array $a, array $b): int => $b[1] <=> $a[1], // highest q first
        );

        return \array_column($parsed, 0);
    }
}
