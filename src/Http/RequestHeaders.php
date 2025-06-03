<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Http;

use Infocyph\ArrayKit\Collection\Collection;
use Psr\Http\Message\ServerRequestInterface;

final class RequestHeaders
{
    private ?Collection $all      = null;   // raw headers + auth extras
    private ?Collection $accept   = null;   // parsed Accept*
    private ?Collection $content  = null;   // parsed Content-*
    private ?Collection $dependency = null; // If-*, Range, Prefer

    public function __construct(private readonly ServerRequestInterface $request)
    {
    }

    /* -----------------------------------------------------------------
       1.  Raw header bag (+ authorisation fall-backs)
       ----------------------------------------------------------------- */
    public function all(): Collection
    {
        if ($this->all) {
            return $this->all;
        }

        // clone PSR-7 header array so we can inject extras
        $hdr = array_map(
            static fn (array $v) => count($v) === 1 ? $v[0] : $v,
            $this->request->getHeaders()
        );

        $this->injectAuthorisation($hdr);

        return $this->all = new Collection($hdr);
    }

    private function injectAuthorisation(array &$hdr): void
    {
        $srv   = $this->request->getServerParams();
        $added = false;

        // ---- Basic / Digest via PHP_AUTH_* --------------------------------
        if (!empty($srv['PHP_AUTH_USER'])) {
            $user        = $srv['PHP_AUTH_USER'];
            $pw          = $srv['PHP_AUTH_PW'] ?? '';
            $hdr['Authorization'] = 'Basic ' . base64_encode("$user:$pw");
            $added = true;
        } elseif (!empty($srv['PHP_AUTH_DIGEST'])) {
            $hdr['Authorization'] = $srv['PHP_AUTH_DIGEST'];
            $added = true;
        }

        // ---- Explicit HTTP_AUTHORIZATION fallback -------------------------
        if (!$added) {
            $line = $srv['HTTP_AUTHORIZATION']
                ?? $srv['REDIRECT_HTTP_AUTHORIZATION']
                ?? null;

            if ($line) {
                $prefix = strtolower(strtok($line, ' '));
                if ($prefix === 'basic') {
                    $hdr += $this->decodeBasic($line);
                }
                $hdr['Authorization'] = $line;   // bearer / digest / whatever
            }
        }
    }

    private function decodeBasic(string $header): array
    {
        $out     = [];
        $decoded = base64_decode(substr($header, 6));
        if ($decoded !== false && str_contains($decoded, ':')) {
            [$user, $pw] = explode(':', $decoded, 2);
            $out['PHP_AUTH_USER'] = $user;
            $out['PHP_AUTH_PW']   = $pw;
        }
        return $out;
    }

    /* -----------------------------------------------------------------
       2.  Accept* parsing  (returns whole set or one key)
       ----------------------------------------------------------------- */
    public function accept(?string $key = null): Collection
    {
        if (!$this->accept) {
            $map = [];
            foreach (['Accept','Accept-Charset','Accept-Encoding','Accept-Language'] as $h) {
                if ($raw = $this->request->getHeaderLine($h)) {
                    $map[$h] = $this->parseAccept($raw);
                }
            }
            $this->accept = new Collection($map);
        }
        return $key ? new Collection($this->accept[$key] ?? []) : $this->accept;
    }

    private function parseAccept(string $raw): array
    {
        $segments = array_map('trim', explode(',', $raw));
        $parsed   = [];

        foreach ($segments as $seg) {
            // split “mimetype ; q=0.8”
            [$mime, $q] = array_pad(array_map('trim', explode(';', $seg, 2)), 2, '');
            $qVal       = (float) (preg_match('/q=([\d.]+)/', $q, $m) ? $m[1] : 1);
            $wild       = substr_count($mime, '*');
            $parsed[]   = ['mime' => $mime, 'q' => $qVal, 'wild' => $wild];
        }

        usort(
            $parsed,
            fn ($a, $b) => [$b['q'], $a['wild']] <=> [$a['q'], $b['wild']]
        );

        return array_column($parsed, 'mime');
    }

    /* -----------------------------------------------------------------
       3.  Content-* helpers
       ----------------------------------------------------------------- */
    public function content(): Collection
    {
        if ($this->content) {
            return $this->content;
        }

        $ct        = strtolower($this->request->getHeaderLine('Content-Type'));
        [$type]    = explode(';', $ct, 2);
        $charset   = null;
        if (preg_match('/charset=([^;]+)/', $ct, $m)) {
            $charset = trim($m[1]);
        }

        return $this->content = new Collection([
            'type'    => $type ?: null,
            'charset' => $charset,
            'length'  => (int) $this->request->getHeaderLine('Content-Length'),
            'md5'     => strtolower($this->request->getHeaderLine('Content-Md5'))
        ]);
    }

    /* -----------------------------------------------------------------
       4.  Conditional / Range helpers
       ----------------------------------------------------------------- */
    public function dependency(?string $key = null): Collection
    {
        if (!$this->dependency) {
            $h = $this->all(); // ensure built

            $dep = [
                'if_match'           => $this->csv($h['If-Match']          ?? ''),
                'if_none_match'      => $this->csv($h['If-None-Match']     ?? ''),
                'if_modified_since'  => $this->httpDate($h['If-Modified-Since'] ?? ''),
                'if_unmodified_since' => $this->httpDate($h['If-Unmodified-Since'] ?? ''),
                'prefer_safe'        => strtolower($h['Prefer'] ?? '') === 'safe'
                    && $this->request->getUri()->getScheme() === 'https',
                'range'              => null,
            ];

            if ($range = $h['Range'] ?? '') {
                [$unit, $span] = array_pad(explode('=', str_replace(' ', '', $range), 2), 2, '');
                $dep['range']  = $unit ? ['unit' => $unit, 'span' => explode(',', $span)] : null;
            }

            $this->dependency = new Collection($dep);
        }

        return $key ? new Collection($this->dependency[$key] ?? []) : $this->dependency;
    }

    /* -----------------------------------------------------------------
       tiny helpers
       ----------------------------------------------------------------- */
    private function csv(string $v): array
    {
        return $v === '' ? [] : preg_split('/\s*,\s*/', $v);
    }

    private function httpDate(string $v): ?int
    {
        return $v === '' ? null : (strtotime($v) ?: null);
    }
}
