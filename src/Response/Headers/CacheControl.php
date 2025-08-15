<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

use Infocyph\Webrick\Request\Support\HeaderBag;

/**
 * Immutable builder for the Cache-Control header.
 *
 * ```php
 * $cc = CacheControl::new()
 *        ->public()->maxAge(60)
 *        ->staleWhileRevalidate(30);
 *
 * $resp = $resp->withHeader('Cache-Control', $cc);
 * ```
 *
 * No reflection, no regex – pure string concat.
 */
final class CacheControl implements \Stringable
{
    /** Known order for stable rendering */
    private const RENDER_ORDER = [
        'no-store',
        'no-cache',
        'private',
        'public',
        'no-transform',
        'must-revalidate',
        'proxy-revalidate',
        'immutable',
        'max-age',
        's-maxage',
        'stale-while-revalidate',
        'stale-if-error',
    ];

    /** Fast membership maps */
    private const BOOL_TOKENS = [
        'no-store' => true,
        'no-cache' => true,
        'no-transform' => true,
        'must-revalidate' => true,
        'proxy-revalidate' => true,
        'immutable' => true,
    ];
    private const PRIVACY_TOKENS = ['public' => true, 'private' => true];
    private const NUM_TOKENS = [
        'max-age' => true,
        's-maxage' => true,
        'stale-while-revalidate' => true,
        'stale-if-error' => true,
    ];
    /** tokens that accept field lists */
    private const FIELD_TOKENS = ['no-cache' => true, 'private' => true];

    /** @var array<string,string|null> token => value|null (builder mode only) */
    private array $parts = [];

    private function __construct(array $existing = [])
    {
        $this->parts = $existing;
    }

    /* ---------- Factory ---------- */

    public static function fromHeaderBag(HeaderBag $bag): self
    {
        $line = $bag->getHeaderLine('Cache-Control');
        if ($line === '') {
            return new self();
        }

        $parsed = [];
        foreach (self::splitCsvRespectingQuotes($line) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            $eqPos = strpos($token, '=');
            if ($eqPos === false) {
                $parsed[strtolower($token)] = null;
            } else {
                $name = strtolower(trim(substr($token, 0, $eqPos)));
                $val = trim(substr($token, $eqPos + 1));
                $parsed[$name] = $val;
            }
        }
        return new self($parsed);
    }

    public static function new(): self
    {
        return new self();
    }

    // put in CacheControl (private static)
    private static function splitCsvRespectingQuotes(string $line): array
    {
        if ($line === '') {
            return [];
        }
        $out = [];
        $buf = '';
        $inQ = false;
        $n = strlen($line);
        for ($i = 0; $i < $n; $i++) {
            $ch = $line[$i];
            if ($ch === '"') {
                $inQ = !$inQ;
                $buf .= $ch;
                continue;
            }
            if ($ch === ',' && !$inQ) {
                $out[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if ($buf !== '') {
            $out[] = trim($buf);
        }
        return array_values(array_filter($out, fn ($s) => $s !== ''));
    }


    /* ---------- Fluent helpers ---------- */

    public function public(): self
    {
        return $this->with('public');
    }

    public function private(): self
    {
        return $this->with('private');
    }

    public function noCache(): self
    {
        return $this->with('no-cache');
    }

    public function noStore(): self
    {
        return $this->with('no-store');
    }

    public function mustRevalidate(): self
    {
        return $this->with('must-revalidate');
    }

    public function proxyRevalidate(): self
    {
        return $this->with('proxy-revalidate');
    }

    public function immutable(): self
    {
        return $this->with('immutable');
    }

    public function maxAge(int $s): self
    {
        return $this->with('max-age', $s);
    }

    public function sMaxAge(int $s): self
    {
        return $this->with('s-maxage', $s);
    }

    public function staleWhileRevalidate(int $s): self
    {
        return $this->with('stale-while-revalidate', $s);
    }

    public function staleIfError(int $s): self
    {
        return $this->with('stale-if-error', $s);
    }

    private function with(string $token, int|string|null $value = null): self
    {
        $token = strtolower($token); // canonicalize once
        $x = clone $this;
        $x->parts[$token] = $value === null ? null : (string)$value;
        // public/private mutually exclusive – last write wins
        if ($token === 'public') {
            unset($x->parts['private']);
        } elseif ($token === 'private') {
            unset($x->parts['public']);
        }
        return $x;
    }

    /* ---------- Render ---------- */

    public function __toString(): string
    {
        $std = [];
        $ext = [];
        foreach ($this->parts as $k => $v) {
            if (isset(self::BOOL_TOKENS[$k]) || isset(self::PRIVACY_TOKENS[$k]) || isset(self::NUM_TOKENS[$k])) {
                $std[$k] = $v;
            } else {
                $ext[$k] = $v;
            }
        }

        $out = [];
        foreach (self::RENDER_ORDER as $k) {
            if (!array_key_exists($k, $std)) {
                continue;
            }
            $v = $std[$k];
            $out[] = $v === null ? $k : "$k=$v";
        }

        if ($ext !== []) {
            ksort($ext);
            foreach ($ext as $k => $v) {
                $out[] = $v === null ? $k : "$k=$v";
            }
        }

        return implode(', ', $out);
    }

    /* ===============================================================
     * Canonicalization / Merge (used by HeaderPolicy::MERGE_TOKENS)
     * =============================================================== */

    /**
     * Canonical, restrictive merge of two Cache-Control lines.
     * $incoming has higher priority (e.g., controller intent).
     */
    public static function canonicalizeMerge(string $existingLine, string $incomingLine): string
    {
        $base = self::parseModel($existingLine);
        $inc = self::parseModel($incomingLine);

        self::mergeFlags($base, $inc);
        self::mergePrivacy($base, $inc);
        self::mergeNumbers($base, $inc);
        self::mergeFieldLists($base, $inc);
        self::mergeExtensions($base, $inc);
        self::normalizeImmutable($base);
        self::applyNoStoreRules($base);

        return self::modelToLine($base);
    }

    /* ---------- Model (typed layout to satisfy static analysis) ---------- */
    /**
     * @return array{
     *   bools: array{
     *     no-store:bool, no-cache:bool, no-transform:bool, must-revalidate:bool, proxy-revalidate:bool, immutable:bool
     *   },
     *   privacy: array{ public:bool, private:bool },
     *   nums: array{ max-age:?int, s-maxage:?int, stale-while-revalidate:?int, stale-if-error:?int },
     *   fields: array{ no-cache: array<string,true>, private: array<string,true> },
     *   ext: array<string,string|null>
     * }
     */
    private static function parseModel(string $line): array
    {
        $m = [
            'bools' => [
                'no-store' => false,
                'no-cache' => false,
                'no-transform' => false,
                'must-revalidate' => false,
                'proxy-revalidate' => false,
                'immutable' => false,
            ],
            'privacy' => ['public' => false, 'private' => false],
            'nums' => [
                'max-age' => null,
                's-maxage' => null,
                'stale-while-revalidate' => null,
                'stale-if-error' => null,
            ],
            'fields' => ['no-cache' => [], 'private' => []],
            'ext' => [],
        ];
        if ($line === '') {
            return $m;
        }

        foreach (self::splitCsvRespectingQuotes($line) as $raw) {
            $tok = trim($raw);
            if ($tok === '') {
                continue;
            }

            $eq = strpos($tok, '=');
            if ($eq === false) {
                $k = strtolower($tok);
                if (isset(self::PRIVACY_TOKENS[$k])) {
                    $m['privacy'][$k] = true;
                } elseif (isset(self::BOOL_TOKENS[$k])) {
                    $m['bools'][$k] = true;
                } else {
                    // extension flag without value
                    if (!array_key_exists($k, $m['ext'])) {
                        $m['ext'][$k] = null;
                    }
                }
                continue;
            }

            $k = strtolower(trim(substr($tok, 0, $eq)));
            $vRaw = trim(substr($tok, $eq + 1));
            $v = trim($vRaw, "\"'");

            if (isset(self::NUM_TOKENS[$k])) {
                $num = self::toIntOrNull($v);
                $m['nums'][$k] = self::minInt($m['nums'][$k], $num);
            } elseif (isset(self::FIELD_TOKENS[$k]) && $v !== '') {
                $m['bools'][$k] = true; // e.g., no-cache, private
                self::ingestCsvFields($m['fields'][$k], $v);
            } elseif (isset(self::BOOL_TOKENS[$k]) || isset(self::PRIVACY_TOKENS[$k])) {
                // boolean with ignored value (rare)
                if (isset(self::PRIVACY_TOKENS[$k])) {
                    $m['privacy'][$k] = true;
                } else {
                    $m['bools'][$k] = true;
                }
            } else {
                if (!array_key_exists($k, $m['ext'])) {
                    $m['ext'][$k] = $v;
                } // keep first; incoming may override
            }
        }

        return $m;
    }

    private static function mergeFlags(array &$base, array $inc): void
    {
        foreach (self::BOOL_TOKENS as $k => $_) {
            $base['bools'][$k] = $base['bools'][$k] || $inc['bools'][$k];
        }
    }

    private static function mergePrivacy(array &$base, array $inc): void
    {
        // private beats public
        $base['privacy']['private'] = $base['privacy']['private'] || $inc['privacy']['private'];
        $base['privacy']['public'] = !$base['privacy']['private'] && ($base['privacy']['public'] || $inc['privacy']['public']);
    }

    private static function mergeNumbers(array &$base, array $inc): void
    {
        foreach (self::NUM_TOKENS as $n => $_) {
            $base['nums'][$n] = self::minInt($base['nums'][$n], $inc['nums'][$n]);
        }
    }

    private static function mergeFieldLists(array &$base, array $inc): void
    {
        // union of fields (Authorization, etc.)
        foreach (self::FIELD_TOKENS as $k => $_) {
            if (!isset($base['fields'][$k])) {
                $base['fields'][$k] = [];
            }
            $base['fields'][$k] = $base['fields'][$k] + $inc['fields'][$k];
            // Mark corresponding bool as present if fields were provided
            if ($base['fields'][$k] !== [] || $base['bools'][$k] || $inc['bools'][$k]) {
                $base['bools'][$k] = true;
            }
        }
    }

    private static function mergeExtensions(array &$base, array $inc): void
    {
        // incoming overrides base on key conflicts
        $base['ext'] = $inc['ext'] + $base['ext'];
    }

    private static function normalizeImmutable(array &$base): void
    {
        if (
            $base['bools']['immutable'] &&
            (
                $base['bools']['no-store'] ||
                $base['bools']['no-cache'] ||
                $base['bools']['must-revalidate'] ||
                $base['bools']['proxy-revalidate']
            )
        ) {
            $base['bools']['immutable'] = false;
        }
    }

    private static function applyNoStoreRules(array &$base): void
    {
        if ($base['bools']['no-store']) {
            foreach (self::NUM_TOKENS as $n => $_) {
                $base['nums'][$n] = null;
            }
            $base['bools']['immutable'] = false;
        }
    }

    /* ---------- Serialize model ---------- */

    private static function modelToLine(array $m): string
    {
        $parts = [];

        if ($m['bools']['no-store']) {
            $parts[] = 'no-store';
        }
        if ($m['bools']['no-cache']) {
            $parts[] = self::renderFieldToken('no-cache', $m['fields']['no-cache']);
        }
        if ($m['privacy']['private']) {
            $parts[] = self::renderFieldToken('private', $m['fields']['private']);
        }
        if ($m['privacy']['public'] && !$m['privacy']['private']) {
            $parts[] = 'public';
        }
        if ($m['bools']['no-transform']) {
            $parts[] = 'no-transform';
        }
        if ($m['bools']['must-revalidate']) {
            $parts[] = 'must-revalidate';
        }
        if ($m['bools']['proxy-revalidate']) {
            $parts[] = 'proxy-revalidate';
        }
        if ($m['bools']['immutable']) {
            $parts[] = 'immutable';
        }

        foreach (self::NUM_TOKENS as $k => $_) {
            $v = $m['nums'][$k];
            if ($v !== null) {
                $parts[] = $k . '=' . $v;
            }
        }

        if ($m['ext'] !== []) {
            ksort($m['ext']);
            foreach ($m['ext'] as $k => $v) {
                $parts[] = $v === null ? $k : "$k=$v";
            }
        }

        return implode(', ', $parts);
    }

    /** @param array<string,true> $fields */
    private static function renderFieldToken(string $name, array $fields): string
    {
        if ($fields === []) {
            return $name;
        }
        $keys = array_keys($fields);
        sort($keys, SORT_STRING);
        return $name . '="' . implode(', ', $keys) . '"';
    }

    /** @param array<string,true> $dst */
    private static function ingestCsvFields(array &$dst, string $csv): void
    {
        foreach (explode(',', $csv) as $f) {
            $f = trim($f);
            if ($f !== '') {
                $dst[$f] = true;
            }
        }
    }

    private static function toIntOrNull(?string $s): ?int
    {
        if ($s === null || $s === '') {
            return null;
        }
        // allow +digits (common) and clamp to >=0
        $digits = ltrim($s, '+');
        return ctype_digit($digits) ? max(0, (int)$digits) : null;
        // (No regex for speed; this outperforms filter_var for tiny strings.)
    }

    private static function minInt(?int $a, ?int $b): ?int
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        return ($a <= $b) ? $a : $b;
    }
}
