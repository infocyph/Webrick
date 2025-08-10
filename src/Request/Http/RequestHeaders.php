<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Infocyph\Webrick\Request\Psr7\ServerRequest;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\HeaderBag;

/**
 * Facade around a PSR-7 Request that
 *  • exposes an immutable HeaderBag (`all()`)
 *  • parses Accept*, Content-*, conditional & range headers
 *  • injects PHP_AUTH_* fallbacks so they behave like real headers
 *  • can extract headers directly from $_SERVER (portable, fast)
 *
 * ZERO allocations on hot path – heavy parsing happens lazily.
 */
final class RequestHeaders
{
    /* -----------------------------------------------------------------
       State (all lazy)
       ----------------------------------------------------------------- */
    private ?HeaderBag $all = null;   // raw + auth fallbacks
    private ?array $accept = null;    // parsed Accept*
    private ?array $content = null;   // Content-Type/Length/MD5
    private ?array $dep = null;       // If-*, Range, Prefer

    public function __construct(private readonly Request|ServerRequest $req) {}

    /* ================================================================
       0) Header extraction (shared, portable)
       ================================================================ */

    /** Import HTTP headers from SAPI/$_SERVER into PSR-7 shape: name => string[] */
    public static function extractFromServer(array $srv): array
    {
        $out = \function_exists('getallheaders')
            ? self::viaGetAllHeaders()
            : self::viaServerFallback($srv);

        self::backfillContentHeaders($srv, $out);
        self::backfillAuthorization($srv, $out);

        return $out;
    }

    /** Fast path when getallheaders() exists. */
    private static function viaGetAllHeaders(): array
    {
        return array_map(function ($val) {
            return \is_array($val) ? \array_values($val) : [(string)$val];
        }, (array)\getallheaders());
    }

    /** Portable fallback: build headers from $_SERVER. */
    private static function viaServerFallback(array $srv): array
    {
        $out = [];
        foreach ($srv as $k => $v) {
            if (\strncmp($k, 'HTTP_', 5) !== 0) {
                continue;
            }
            // HTTP_ACCEPT_ENCODING → Accept-Encoding
            $name = \str_replace(
                ' ',
                '-',
                \ucwords(\strtolower(\strtr(\substr($k, 5), '_', ' '))),
            );
            $out[$name] = [\is_array($v) ? \implode(',', $v) : (string)$v];
        }
        return $out;
    }

    /** Ensure Content-* headers exist when SAPIs place them only in $_SERVER. */
    private static function backfillContentHeaders(array $srv, array &$out): void
    {
        foreach (
            [
                'CONTENT_TYPE' => 'Content-Type',
                'CONTENT_LENGTH' => 'Content-Length',
                'CONTENT_MD5' => 'Content-Md5',
            ] as $sk => $hn
        ) {
            if (isset($srv[$sk]) && !isset($out[$hn])) {
                $out[$hn] = [(string)$srv[$sk]];
            }
        }
    }

    /** Some servers strip Authorization; restore from $_SERVER if available. */
    private static function backfillAuthorization(array $srv, array &$out): void
    {
        if (isset($out['Authorization'])) {
            return;
        }
        if (isset($srv['HTTP_AUTHORIZATION'])) {
            $out['Authorization'] = [(string)$srv['HTTP_AUTHORIZATION']];
        } elseif (isset($srv['REDIRECT_HTTP_AUTHORIZATION'])) {
            $out['Authorization'] = [(string)$srv['REDIRECT_HTTP_AUTHORIZATION']];
        }
    }

    /* ================================================================
       1) Raw header bag  (with Basic/Digest fallbacks)
       ================================================================ */
    public function all(): HeaderBag
    {
        if ($this->all) {
            return $this->all;
        }

        // Copy PSR-7 headers (values already arrays). Defensive fallback if empty.
        $hdr = $this->req->getHeaders();
        if ($hdr === []) {
            $hdr = self::extractFromServer($this->req->getServerParams());
        }

        $this->injectAuthorisation($hdr);

        return $this->all = new HeaderBag($hdr);
    }

    /** Add Authorization header when PHP_AUTH_* populated (and related fallbacks). */
    private function injectAuthorisation(array &$hdr): void
    {
        $srv = $this->req->getServerParams();
        $added = false;

        $this->injectFromPhpAuth($srv, $hdr, $added);
        $this->injectFromExplicitAuthorization($srv, $hdr, $added);
    }

    /** Populate from PHP_AUTH_USER/PHP_AUTH_PW or PHP_AUTH_DIGEST. */
    private function injectFromPhpAuth(array $srv, array &$hdr, bool &$added): void
    {
        if (!empty($srv['PHP_AUTH_USER']) && !isset($hdr['Authorization'])) {
            $pw = $srv['PHP_AUTH_PW'] ?? '';
            $hdr['Authorization'] = ['Basic ' . base64_encode($srv['PHP_AUTH_USER'] . ':' . $pw)];
            $added = true;
            return;
        }
        if (!empty($srv['PHP_AUTH_DIGEST'])) {
            $hdr['Authorization'] = [$srv['PHP_AUTH_DIGEST']];
            $added = true;
        }
    }

    /** Fallback to HTTP_AUTHORIZATION/REDIRECT_HTTP_AUTHORIZATION; backfill PHP_AUTH_* for Basic. */
    private function injectFromExplicitAuthorization(array $srv, array &$hdr, bool $alreadyAdded): void
    {
        if ($alreadyAdded) {
            return;
        }
        $line = $srv['HTTP_AUTHORIZATION']
            ?? $srv['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if (!$line) {
            return;
        }

        $hdr['Authorization'] ??= [$line];

        // also back-fill PHP_AUTH_* for Basic (helps downstream libs)
        if (\str_starts_with(\strtolower($line), 'basic ')) {
            $this->backfillPhpAuthFromBasicHeader($line, $hdr);
        }
    }

    /** Decode Basic header and backfill PHP_AUTH_USER/PHP_AUTH_PW. */
    private function backfillPhpAuthFromBasicHeader(string $line, array &$hdr): void
    {
        $cred = \base64_decode(\substr($line, 6));
        if ($cred === false || !\str_contains($cred, ':')) {
            return;
        }
        [$u, $p] = \explode(':', $cred, 2);
        $hdr['PHP_AUTH_USER'] = [$u];
        $hdr['PHP_AUTH_PW'] = [$p];
    }

    /* ================================================================
       2)  Accept* parsing – returns whole map or one header
       ================================================================ */
    public function accept(?string $key = null): array
    {
        if ($this->accept === null) {
            $map = [];
            foreach (['Accept', 'Accept-Charset', 'Accept-Encoding', 'Accept-Language'] as $h) {
                if ($raw = $this->req->getHeaderLine($h)) {
                    $map[$h] = $this->parseAccept($raw);
                }
            }
            $this->accept = $map;
        }
        return $key ? ($this->accept[$key] ?? []) : $this->accept;
    }

    /** RFC 9110 §12 quality weighting + wildcard handling. */
    private function parseAccept(string $raw): array
    {
        $segments = explode(',', $raw); // faster than preg_split
        $parsed = [];

        foreach ($segments as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                continue;
            }
            [$mime, $q] = array_pad(array_map('trim', explode(';', $seg, 2)), 2, '');
            $qVal = (float)(preg_match('/q=([\d.]+)/', $q, $m) ? $m[1] : 1);
            if ($qVal == 0.0) {
                continue; // not acceptable
            }
            $wild = substr_count($mime, '*');
            $parsed[] = ['mime' => $mime, 'q' => $qVal, 'wild' => $wild];
        }

        usort($parsed, fn($a, $b) => [$b['q'], $a['wild']] <=> [$a['q'], $b['wild']]);
        return array_column($parsed, 'mime');
    }

    /* ================================================================
       3)  Content-* helpers
       ================================================================ */
    public function content(): array
    {
        if ($this->content !== null) {
            return $this->content;
        }

        $ct = strtolower($this->req->getHeaderLine('Content-Type'));
        [$type] = explode(';', $ct, 2);
        $charset = preg_match('/charset=([^;]+)/', $ct, $m) ? trim($m[1]) : null;
        $lenLine = $this->req->getHeaderLine('Content-Length');

        return $this->content = [
            'type' => $type ?: null,
            'charset' => $charset,
            'length' => ($lenLine !== '' ? (int)$lenLine : null),
            'md5' => strtolower($this->req->getHeaderLine('Content-Md5')),
        ];
    }

    /* ================================================================
       4)  Conditional / Range helpers
       ================================================================ */
    public function dependency(?string $key = null): array
    {
        if ($this->dep !== null) {
            return $key ? ($this->dep[$key] ?? []) : $this->dep;
        }

        $h = $this->all(); // HeaderBag
        $rangeLine = $h->getHeaderLine('Range');

        $dep = [
            'if_match' => $this->csv($h->getHeaderLine('If-Match')),
            'if_none_match' => $this->csv($h->getHeaderLine('If-None-Match')),
            'if_modified_since' => $this->httpDate($h->getHeaderLine('If-Modified-Since')),
            'if_unmodified_since' => $this->httpDate($h->getHeaderLine('If-Unmodified-Since')),
            'prefer_safe' => (strtolower($h->first('Prefer') ?? '') === 'safe')
                && $this->req->getUri()->getScheme() === 'https',
            'range' => null,
        ];

        if ($rangeLine !== '') {
            [$unit, $span] = array_pad(explode('=', str_replace(' ', '', $rangeLine), 2), 2, '');
            $dep['range'] = $unit ? ['unit' => $unit, 'span' => explode(',', $span)] : null;
        }

        $this->dep = $dep;
        return $key ? ($dep[$key] ?? []) : $dep;
    }

    /* ================================================================
       tiny helpers (no allocations)
       ================================================================ */
    private function csv(string $v): array
    {
        return $v === '' ? [] : preg_split('/\s*,\s*/', $v);
    }

    private function httpDate(string $v): ?int
    {
        return $v === '' ? null : (strtotime($v) ?: null);
    }
}
