<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Request\Support\HeaderBag;

/**
 * Facade around a PSR-7 Request that
 *  • exposes an immutable HeaderBag (`all()`)
 *  • parses Accept*, Content-*, conditional & range headers
 *  • injects PHP_AUTH_* fallbacks so they behave like real headers
 *
 * ZERO allocations on hot path – heavy parsing happens lazily.
 */
final class RequestHeaders
{
    /* -----------------------------------------------------------------
       State (all lazy)
       ----------------------------------------------------------------- */
    private ?HeaderBag $all = null;   // raw + auth fallbacks
    private ?array $accept = null;   // parsed Accept*
    private ?array $content = null;   // Content-Type/Length/MD5
    private ?array $dep = null;   // If-*, Range, Prefer

    public function __construct(private readonly Request $req)
    {
    }

    /* ================================================================
       1) Raw header bag  (with Basic/Digest fallbacks)
       ================================================================ */
    public function all(): HeaderBag
    {
        if ($this->all) {
            return $this->all;
        }

        // copy PSR-7 headers (values already arrays)
        $hdr = $this->req->getHeaders();

        $this->injectAuthorisation($hdr);

        /** @psalm-suppress InvalidArgument */
        return $this->all = new HeaderBag($hdr);
    }

    /** Add Authorization header when PHP_AUTH_* populated. */
    private function injectAuthorisation(array &$hdr): void
    {
        $srv = $this->req->getServerParams();
        $added = false;

        // Basic / Digest via PHP_AUTH_*
        if (!empty($srv['PHP_AUTH_USER']) && !isset($hdr['Authorization'])) {
            $pw = $srv['PHP_AUTH_PW'] ?? '';
            $hdr['Authorization'] = ['Basic ' . base64_encode($srv['PHP_AUTH_USER'] . ':' . $pw)];
            $added = true;
        } elseif (!empty($srv['PHP_AUTH_DIGEST'])) {
            $hdr['Authorization'] = [$srv['PHP_AUTH_DIGEST']];
            $added = true;
        }

        // Explicit HTTP_AUTHORIZATION fallback
        if (!$added) {
            $line = $srv['HTTP_AUTHORIZATION']
                ?? $srv['REDIRECT_HTTP_AUTHORIZATION']
                ?? null;

            if ($line) {
                $hdr['Authorization'] ??= [$line];

                // also back-fill PHP_AUTH_* for Basic
                if (str_starts_with(strtolower($line), 'basic ')) {
                    $cred = base64_decode(substr($line, 6));
                    if ($cred !== false && str_contains($cred, ':')) {
                        [$u, $p] = explode(':', $cred, 2);
                        $hdr['PHP_AUTH_USER'] = [$u];
                        $hdr['PHP_AUTH_PW'] = [$p];
                    }
                }
            }
        }
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
        $segments = explode(',', $raw);      // faster than preg_split
        $parsed = [];

        foreach ($segments as $seg) {
            $seg = trim($seg);
            if ($seg === '') {
                continue;
            }
            [$mime, $q] = array_pad(array_map('trim', explode(';', $seg, 2)), 2, '');
            $qVal = (float)(preg_match('/q=([\d.]+)/', $q, $m) ? $m[1] : 1);
            if ($qVal == 0.0) {                // RFC 9110: not acceptable
                continue;                       // ← cheap hard-skip, keeps array small
            }
            $wild = substr_count($mime, '*');
            $parsed[] = ['mime' => $mime, 'q' => $qVal, 'wild' => $wild];
        }

        usort($parsed, fn ($a, $b) => [$b['q'], $a['wild']] <=> [$a['q'], $b['wild']]);
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
        $charset = null;
        if (preg_match('/charset=([^;]+)/', $ct, $m)) {
            $charset = trim($m[1]);
        }

        return $this->content = [
            'type' => $type ?: null,
            'charset' => $charset,
            'length' => (int)$this->req->getHeaderLine('Content-Length'),
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

        $h = $this->all();     // ensures Authorisation injected

        $dep = [
            'if_match' => $this->csv($h['If-Match'] ?? ''),
            'if_none_match' => $this->csv($h['If-None-Match'] ?? ''),
            'if_modified_since' => $this->httpDate($h['If-Modified-Since'] ?? ''),
            'if_unmodified_since' => $this->httpDate($h['If-Unmodified-Since'] ?? ''),
            'prefer_safe' => strcasecmp($h['Prefer'] ?? '', 'safe') === 0
                && $this->req->getUri()->getScheme() === 'https',
            'range' => null,
        ];

        if ($range = $h['Range'] ?? '') {
            [$unit, $span] = array_pad(explode('=', str_replace(' ', '', $range), 2), 2, '');
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
