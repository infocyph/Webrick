<?php

/**
 * Webrick - HTTP response compression middleware.
 *
 * Performs transparent content-encoding based on Accept-Encoding negotiation:
 * - Negotiates among zstd, brotli, gzip (and optional deflate) with configurable preference.
 * - Skips compression for small, non-compressible, partial, or streaming responses.
 * - Adds/adjusts Vary and ETag validators based on the chosen encoding strategy.
 *
 * ETag strategies:
 * - weak-on-encode: Make existing tags weak; synthesize weak tag when absent.
 * - recompute-strong (default): Compute strong tag from encoded bytes.
 * - derive-strong: Derive deterministic tag from base ETag + alg/level when safe.
 *
 * @package Infocyph\Webrick\Middleware
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\StreamUtil;

/**
 * Content-encoding middleware with ETag management.
 */
final readonly class CompressionMiddleware
{
    public const ETAG_STRONG_DERIVE = 'derive-strong';    // from base tag + alg/level
    public const ETAG_STRONG_RECOMP = 'recompute-strong'; // default (bytes-on-the-wire)
    /* ─────────── ETag strategies ─────────── */
    public const ETAG_WEAK_ON_ENCODE = 'weak-on-encode';

    /** encoder → callable (only invoked when extension is loaded) */
    private const ALGO = [
        'br' => 'brotli_compress',   // ext-brotli
        'zstd' => 'zstd_compress',     // ext-zstd
        'gzip' => 'gzencode',          // ext-zlib (bundled)
        'deflate' => 'gzdeflate',         // ext-zlib (optional; HTTP "deflate")
    ];

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

    /**
     * Configure compression thresholds, preference, and ETag behavior.
     *
     * @param int $minBytes Minimum response size to consider encoding.
     * @param array<int,string> $prefOrder Preferred encoders in order (subset of ['zstd','br','gzip','deflate']).
     * @param string $etagMode One of ETAG_* constants.
     * @param int $gzipLevel gzip level (zlib).
     * @param int $brotliQuality Brotli quality parameter.
     * @param int $zstdLevel Zstd compression level.
     * @param string $etagDeriveSalt Salt used for derive-strong mode.
     * @param int $maxBufferBytes Safety ceiling for in-memory encoding buffer.
     * @param array<int,string> $excludeTypes Extra MIME patterns to skip (e.g., ['application/pdf','image/*']).
     * @param array<int,string> $onlyTypes Whitelist; if non-empty, only these patterns are compressible.
     * @param bool $forceAddVary Ensure Vary includes Accept-Encoding when encoding is applied.
     */
    public function __construct(
        private int $minBytes = 1400,
        private array $prefOrder = ['zstd', 'br', 'gzip'],
        private string $etagMode = self::ETAG_WEAK_ON_ENCODE,
        private int $gzipLevel = 6,
        private int $brotliQuality = 4,
        private int $zstdLevel = 3,
        private string $etagDeriveSalt = 'enc-v1',
        private int $maxBufferBytes = 8_388_608, // 8 MiB hard ceiling for in-memory re-encode
        private array $excludeTypes = [],
        private array $onlyTypes = [],
        private bool $forceAddVary = true,
    ) {
    }

    /**
     * Negotiate, encode, and adjust validators for the response when appropriate.
     *
     * @param Request $req
     * @param Closure $next
     *
     * @return Response Possibly encoded response with adjusted ETag.
     */
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

        if ($this->forceAddVary) {
            $req = VaryAccumulatorMiddleware::add($req, 'Accept-Encoding');
        }

        $resp = $this->applyEncoded($resp, $enc, $alg);
        return $this->adjustValidators($req, $resp, $enc, $alg);
    }

    /**
     * Adjust ETag validators according to configured strategy.
     *
     * @param Request $req
     * @param Response $resp
     * @param string $encodedBytes
     * @param string $alg
     *
     * @return Response Response with updated ETag when applicable.
     */
    private function adjustValidators(Request $req, Response $resp, string $encodedBytes, string $alg): Response
    {
        // Only manipulate ETag for GET/HEAD responses
        $m = strtoupper($req->getMethod());
        if ($m !== 'GET' && $m !== 'HEAD') {
            return $resp;
        }

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

                    if ($base === '') {
                        return $resp->withSmartHeader('ETag', $this->strongFromBytes($encodedBytes));
                    }

                    if ($isWeak) {
                        $level = $this->encodedLevelToken($alg);
                        $derived = 'W/"' . substr(
                            hash('xxh3', $base . '|' . $alg . '|' . $level . '|' . $this->etagDeriveSalt, false),
                            0,
                            16,
                        ) . '"';
                        return $resp->withSmartHeader('ETag', $derived);
                    }

                    if ($this->isEncodingDeterministic($alg)) {
                        $level = $this->encodedLevelToken($alg);
                        $derived = '"' . substr(
                            hash('xxh3', $base . '|' . $alg . '|' . $level . '|' . $this->etagDeriveSalt, false),
                            0,
                            16,
                        ) . '"';
                        return $resp->withSmartHeader('ETag', $derived);
                    }

                    return $resp->withSmartHeader('ETag', $this->strongFromBytes($encodedBytes));
                }

            case self::ETAG_STRONG_RECOMP:
            default:
                $resp = $resp->withSmartHeader('ETag', $this->strongFromBytes($encodedBytes));
                break;
        }

        return $resp;
    }

    /**
     * Apply body and headers for the encoded response.
     *
     * @param Response $resp Original response.
     * @param string $enc Encoded bytes.
     * @param string $alg Encoding algorithm.
     *
     * @return Response Response with Content-Encoding and adjusted headers.
     */
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

    /* ───────────────────────── encoding ───────────────────────── */

    /**
     * Encode raw bytes using the selected algorithm.
     *
     * @param string $raw Raw response bytes.
     * @param string $alg Algorithm identifier ('gzip','br','zstd','deflate').
     *
     * @return string|false Encoded bytes or false when encoder unavailable/fails.
     */
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
            $alg === 'deflate' && \function_exists(self::ALGO['deflate']) => \gzdeflate($raw, $this->gzipLevel),
            default => false,
        };
    }

    /**
     * Encode level token used for derive-strong strategy.
     *
     * @param string $alg Algorithm identifier.
     *
     * @return string Level token.
     */
    private function encodedLevelToken(string $alg): string
    {
        return match ($alg) {
            'gzip', 'deflate' => (string)$this->gzipLevel,
            'br' => (string)$this->brotliQuality,
            'zstd' => (string)$this->zstdLevel,
            default => '0',
        };
    }

    /* ───────────────────────── helpers ─────────────────────────── */

    /**
     * True when Cache-Control contains no-transform (case-insensitive).
     */
    private function hasNoTransform(Response $r): bool
    {
        $cc = strtolower($r->getHeaderLine('Cache-Control'));
        return $cc !== '' && str_contains($cc, 'no-transform');
    }

    /** True when `$ctype` matches any user-provided onlyTypes pattern. */
    private function isAllowedByWhitelist(string $ctype): bool
    {
        if ($this->onlyTypes === []) {
            return true; // no whitelist ⇒ allow
        }
        return array_any($this->onlyTypes, fn ($pat) => $pat !== '' && $this->mimeMatches($ctype, strtolower($pat)));
    }

    /** gzip via gzencode adds an MTIME by default → bytes can vary run-to-run. */
    private function isEncodingDeterministic(string $alg): bool
    {
        // If you later make gzip deterministic (e.g., zero MTIME), flip this.
        return $alg !== 'gzip';
    }

    /** True when `$ctype` starts with any of the NO_COMPRESS prefixes or matches user excludes. */
    private function isNonCompressible(string $ctype): bool
    {
        // Built-in hard excludes
        foreach (self::NO_COMPRESS_PREFIXES as $prefix) {
            if (\str_starts_with($ctype, $prefix)) {
                return true;
            }
        }
        return array_any($this->excludeTypes, fn ($pat) => $pat !== '' && $this->mimeMatches($ctype, strtolower($pat)));
    }

    /**
     * Simple MIME matcher:
     *  - exact: "application/json"
     *  - wildcard: "text/*"
     *  - prefix convenience: "text/" acts like "text/*"
     */
    private function mimeMatches(string $mime, string $pattern): bool
    {
        $mime = strtolower(trim($mime));
        $semicolon = strpos($mime, ';');
        if ($semicolon !== false) {
            $mime = substr($mime, 0, $semicolon); // strip parameters
        }
        $pattern = trim($pattern);

        if ($pattern === '*/*' || $pattern === '*') {
            return true;
        }
        if (str_ends_with($pattern, '/*')) {
            $prefix = substr($pattern, 0, -1); // keep trailing slash
            return \str_starts_with($mime, $prefix);
        }
        if (str_ends_with($pattern, '/')) {
            return \str_starts_with($mime, $pattern);
        }
        return $mime === $pattern;
    }

    /**
     * Return the best supported encoding from Accept-Encoding or null.
     *
     * @param string $header Raw Accept-Encoding value.
     *
     * @return string|null Chosen encoding or null.
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

    /**
     * Parse and sort Accept-Encoding into tokens by q-value (desc), filtering q=0.
     *
     * @param string $header Raw header.
     *
     * @return array<int,string> Tokens sorted by preference.
     */
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

    /**
     * Parse an ETag header value into its base value and weakness flag.
     *
     * @param string $etagLine Raw ETag header line (may include W/ prefix and quotes).
     *
     * @return array{0:string,1:bool} [valueWithoutQuotes, isWeak]. Blank value means “no ETag present”.
     */
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

    /* ───────────────────────── decisions ───────────────────────── */

    /**
     * Decide whether the response is eligible and practical to compress.
     */
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
                if ($contentType === '') {
                    return false; // unknown ⇒ allow (subject to size)
                }
                return $this->isNonCompressible($contentType) || !$this->isAllowedByWhitelist($contentType);
            })() => false,
            default => true,
        };
    }

    /**
     * Strip weak prefix and surrounding quotes from an ETag line.
     */
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

    /**
     * Compute a short, strong ETag from encoded bytes.
     */
    private function strongFromBytes(string $bytes): string
    {
        return '"' . substr(hash('xxh3', $bytes, false), 0, 16) . '"';  // strong, short, cache-friendly
    }
}
