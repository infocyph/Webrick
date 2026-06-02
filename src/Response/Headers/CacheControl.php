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
 *
 * @phpstan-type CcBools array{
 *   no-store: bool,
 *   no-cache: bool,
 *   no-transform: bool,
 *   must-revalidate: bool,
 *   proxy-revalidate: bool,
 *   immutable: bool
 * }
 * @phpstan-type CcPrivacy array{public: bool, private: bool}
 * @phpstan-type CcNums array{
 *   max-age: int|null,
 *   s-maxage: int|null,
 *   stale-while-revalidate: int|null,
 *   stale-if-error: int|null
 * }
 * @phpstan-type CcFields array{
 *   no-cache: array<string, true>,
 *   private: array<string, true>
 * }
 * @phpstan-type CcModel array{
 *   bools: CcBools,
 *   privacy: CcPrivacy,
 *   nums: CcNums,
 *   fields: CcFields,
 *   ext: array<string, string|null>
 * }
 */
final class CacheControl implements \Stringable
{
    /** Fast membership maps */
    private const array BOOL_TOKENS = [
        'no-store' => true,
        'no-cache' => true,
        'no-transform' => true,
        'must-revalidate' => true,
        'proxy-revalidate' => true,
        'immutable' => true,
    ];

    /** tokens that accept field lists */
    private const array FIELD_TOKENS = ['no-cache' => true, 'private' => true];

    private const array NUM_TOKENS = [
        'max-age' => true,
        's-maxage' => true,
        'stale-while-revalidate' => true,
        'stale-if-error' => true,
    ];

    private const array PRIVACY_TOKENS = ['public' => true, 'private' => true];

    /** Known order for stable rendering */
    private const array RENDER_ORDER = [
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

    /**
     * Private constructor for the CacheControl class.
     *
     * This constructor is only used internally by the factory methods.
     *
     * @param array<string, string|null> $parts Existing Cache-Control parts
     */
    private function __construct(private array $parts = []) {}

    /**
     * Renders the Cache-Control header value as a single string.
     *
     * This method takes care of rendering the standard Cache-Control tokens
     * (e.g., `public`, `private`, `no-cache`, `no-store`, `max-age`, `s-maxage`,
     * `stale-while-revalidate`, `stale-if-error`) in the correct order, and
     * also renders any extension Cache-Control tokens (in alphabetical order).
     *
     * @return string The rendered Cache-Control header value.
     */
    public function __toString(): string
    {
        [$std, $ext] = self::partitionParts($this->parts);

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

    /**
     * Merges two Cache-Control header lines into a single canonical line.
     *
     * The merging process follows HTTP caching semantics, ensuring that the resulting
     * Cache-Control header is valid and respects the most restrictive directives from both inputs.
     *
     * @param string $existingLine The existing Cache-Control header line.
     * @param string $incomingLine The incoming Cache-Control header line to merge.
     * @return string The merged and canonicalized Cache-Control header line.
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

    /* ---------- Factory ---------- */

    /**
     * Reconstructs a CacheControl instance from a HeaderBag.
     *
     * If the HeaderBag does not contain a Cache-Control header, an empty CacheControl instance is returned.
     *
     * @param HeaderBag $bag The HeaderBag to reconstruct the CacheControl instance from.
     * @return static The reconstructed CacheControl instance.
     */
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

    /**
     * Creates a new CacheControl instance with no settings.
     *
     * Use this to start building a Cache-Control header from scratch.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Marks the response as immutable.
     *
     * The immutable Cache-Control directive indicates that this response
     * will not be updated while it is fresh.
     */
    public function immutable(): self
    {
        return $this->with('immutable');
    }

    /**
     * Sets the maximum age of the response in seconds.
     * The max-age Cache-Control directive indicates that the client is
     * eligible to use this response until the specified number of
     * seconds have passed since the response was generated.
     *
     * @param int $s Number of seconds
     */
    public function maxAge(int $s): self
    {
        return $this->with('max-age', $s);
    }

    /**
     * Instructs caching intermediaries (notably CDNs) to revalidate the response
     * after it has become stale, but before the end-user is impacted.
     *
     * This directive is usually set by a caching layer (like a CDN) to
     * reduce the load on the origin server.
     */
    public function mustRevalidate(): self
    {
        return $this->with('must-revalidate');
    }

    /**
     * Instructs caching layers to not cache the response at all.
     * This directive is usually set by the origin server to prevent caching of
     * sensitive information.
     */
    public function noCache(): self
    {
        return $this->with('no-cache');
    }

    /**
     * Disables the response from being stored by any caching layer.
     */
    public function noStore(): self
    {
        return $this->with('no-store');
    }

    /**
     * Sets the Cache-Control header to "private", indicating that the response should
     * not be cached by shared caches. This directive is usually set by the origin
     * server to prevent caching of sensitive information.
     */
    public function private(): self
    {
        return $this->with('private');
    }

    /**
     * Instructs caching intermediaries (notably CDNs) to revalidate the response
     * after it has become stale, but before the end-user is impacted.
     *
     * This directive is usually set by a caching layer (like a CDN) to
     * instruct any downstream caches to revalidate their cached copy of the
     * response after it has become stale.
     */
    public function proxyRevalidate(): self
    {
        return $this->with('proxy-revalidate');
    }

    /**
     * Instructs caching layers to cache the response publicly.
     * This directive is usually set by the origin server to allow caching of
     * publicly accessible resources.
     */
    public function public(): self
    {
        return $this->with('public');
    }

    /**
     * The s-maxage Cache-Control extension indicates that the shared cache
     * should not revalidate the response until either the specified number
     * of seconds have passed or the response's Age header value is greater
     * than the specified number of seconds.
     *
     * @param int $s Number of seconds
     */
    public function sMaxAge(int $s): self
    {
        return $this->with('s-maxage', $s);
    }

    /**
     * The stale-if-error Cache-Control extension allows the origin server
     * to explicitly indicate that when an error occurs, a cached response
     * can be used in place of the 5xx response that would have been
     * generated due to the error, without requiring the end user to
     * revalidate the cached copy against the origin server.
     *
     * @param int $s Maximum number of seconds that the response can be served
     *               stale in case of an error.
     * @return self New instance with the specified Cache-Control directive set
     */
    public function staleIfError(int $s): self
    {
        return $this->with('stale-if-error', $s);
    }

    /**
     * The stale-while-revalidate Cache-Control extension indicates that the
     * client should not revalidate the response until either the
     * specified number of seconds have passed or the response's Age
     * header value is greater than the specified number of seconds.
     *
     * @param int $s Maximum number of seconds to serve the response stale.
     * @return self New instance with the specified Cache-Control directive set.
     */
    public function staleWhileRevalidate(int $s): self
    {
        return $this->with('stale-while-revalidate', $s);
    }

    /**
     * @param list<string> $parts
     * @param array<string,string|null> $ext
     */
    private static function appendExtensionTokens(array &$parts, array $ext): void
    {
        if ($ext === []) {
            return;
        }

        ksort($ext);
        foreach ($ext as $k => $v) {
            $parts[] = $v === null ? $k : "{$k}={$v}";
        }
    }

    /**
     * @param list<string> $parts
     * @param array<string,true> $fields
     */
    private static function appendFieldToken(array &$parts, bool $enabled, string $name, array $fields): void
    {
        if ($enabled) {
            $parts[] = self::renderFieldToken($name, $fields);
        }
    }

    /**
     * @param list<string> $parts
     */
    private static function appendFlagToken(array &$parts, bool $enabled, string $token): void
    {
        if ($enabled) {
            $parts[] = $token;
        }
    }

    /**
     * @param list<string> $parts
     * @param array{max-age:?int,s-maxage:?int,stale-while-revalidate:?int,stale-if-error:?int} $nums
     */
    private static function appendNumericTokens(array &$parts, array $nums): void
    {
        foreach (self::NUM_TOKENS as $k => $_) {
            $v = $nums[$k];
            if ($v !== null) {
                $parts[] = $k . '=' . $v;
            }
        }
    }

    /**
     * @param list<string> $parts
     * @param array{public:bool,private:bool} $privacy
     * @param array{no-cache:array<string,true>,private:array<string,true>} $fields
     */
    private static function appendPrivacyToken(array &$parts, array $privacy, array $fields): void
    {
        if ($privacy['private']) {
            $parts[] = self::renderFieldToken('private', $fields['private']);

            return;
        }

        if ($privacy['public']) {
            $parts[] = 'public';
        }
    }

    /**
     * Applies the rules of the no-store directive on the Cache-Control model.
     *
     * If no-store is present, it sets all numeric tokens to null and disables immutable.
     *
     * @param CcModel $base
     */
    private static function applyNoStoreRules(array &$base): void
    {
        if ($base['bools']['no-store']) {
            foreach (self::NUM_TOKENS as $n => $_) {
                $base['nums'][$n] = null;
            }
            $base['bools']['immutable'] = false;
        }
    }

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
    private static function emptyModel(): array
    {
        return [
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
    }

    /**
     * @param CcModel $m
     */
    private static function ingestBareToken(array &$m, string $k): void
    {
        if (isset(self::PRIVACY_TOKENS[$k])) {
            $m['privacy'][$k] = true;

            return;
        }
        if (isset(self::BOOL_TOKENS[$k])) {
            $m['bools'][$k] = true;

            return;
        }

        if (!\array_key_exists($k, $m['ext'])) {
            $m['ext'][$k] = null;
        }
    }

    /** @param array<string,true> $dst */

    /**
     * Populate an array with boolean values from a comma-separated string.
     * Each token in the string is used as a key in the array and the value is always true.
     *
     * @param array<string, true> &$dst Destination array
     * @param string $csv comma-separated string to process
     */
    private static function ingestCsvFields(array &$dst, string $csv): void
    {
        foreach (explode(',', $csv) as $f) {
            $f = trim($f);
            if ($f !== '') {
                $dst[$f] = true;
            }
        }
    }

    /**
     * @param CcModel $m
     */
    private static function ingestModelToken(array &$m, string $raw): void
    {
        $tok = \trim($raw);
        if ($tok === '') {
            return;
        }

        $eq = \strpos($tok, '=');
        if ($eq === false) {
            self::ingestBareToken($m, \strtolower($tok));

            return;
        }

        $k = \strtolower(\trim(\substr($tok, 0, $eq)));
        $v = \trim(\trim(\substr($tok, $eq + 1)), "\"'");
        self::ingestValuedToken($m, $k, $v);
    }

    /**
     * @param CcModel $m
     */
    private static function ingestValuedToken(array &$m, string $k, string $v): void
    {
        if (isset(self::NUM_TOKENS[$k])) {
            $num = self::toIntOrNull($v);
            $m['nums'][$k] = self::minInt($m['nums'][$k], $num);

            return;
        }

        if (isset(self::FIELD_TOKENS[$k]) && $v !== '') {
            if ($k === 'private') {
                $m['privacy']['private'] = true;
            } else {
                $m['bools'][$k] = true;
            }
            self::ingestCsvFields($m['fields'][$k], $v);

            return;
        }

        if (isset(self::PRIVACY_TOKENS[$k])) {
            $m['privacy'][$k] = true;

            return;
        }
        if (isset(self::BOOL_TOKENS[$k])) {
            $m['bools'][$k] = true;

            return;
        }

        if (!\array_key_exists($k, $m['ext'])) {
            $m['ext'][$k] = $v;
        }
    }

    /**
     * Merge two extension lists, with incoming extensions overriding base on key conflicts.
     *
     * Extension lists are associative arrays where the key is the extension name and the value is the extension value.
     *
     * @param CcModel $base the base extension list to be merged with
     * @param CcModel $inc the incoming extension list to be merged
     */
    private static function mergeExtensions(array &$base, array $inc): void
    {
        // incoming overrides base on key conflicts
        $base['ext'] = $inc['ext'] + $base['ext'];
    }

    /**
     * Merges two associative arrays of fields, with the incoming fields array overriding the base fields array on key conflicts.
     * If the resulting array of fields is non-empty, or if the corresponding boolean flag is present in either the base or incoming arrays,
     * then the corresponding boolean flag is set to true in the base array.
     */
    /**
     * @param CcModel $base
     * @param CcModel $inc
     */
    private static function mergeFieldLists(array &$base, array $inc): void
    {
        $base['fields']['no-cache'] = $base['fields']['no-cache'] + $inc['fields']['no-cache'];
        if (
            $base['fields']['no-cache'] !== []
            || $base['bools']['no-cache']
            || $inc['bools']['no-cache']
        ) {
            $base['bools']['no-cache'] = true;
        }

        $base['fields']['private'] = $base['fields']['private'] + $inc['fields']['private'];
        if (
            $base['fields']['private'] !== []
            || $base['privacy']['private']
            || $inc['privacy']['private']
        ) {
            $base['privacy']['private'] = true;
        }
    }

    /**
     * Merge two associative arrays of cache control boolean directives.
     *
     * @param CcModel $base The base array to merge into
     * @param CcModel $inc The array to merge from
     */
    private static function mergeFlags(array &$base, array $inc): void
    {
        foreach (self::BOOL_TOKENS as $k => $_) {
            $base['bools'][$k] = $base['bools'][$k] || $inc['bools'][$k];
        }
    }

    /**
     * Merge two associative arrays of cache control directives with numerical values.
     *
     * Each directive in the incoming array overrides the corresponding directive in the base array.
     * The resulting array of directives will have the minimum value of the same directive from either the base or incoming array.
     *
     * @param CcModel $base The base array of directives.
     * @param CcModel $inc The incoming array of directives to merge.
     */
    private static function mergeNumbers(array &$base, array $inc): void
    {
        foreach (self::NUM_TOKENS as $n => $_) {
            $base['nums'][$n] = self::minInt($base['nums'][$n], $inc['nums'][$n]);
        }
    }

    /**
     * Merge two associative arrays of cache control privacy directives.
     *
     * private beats public. If either the $base or $inc array has a "private" key set to true,
     * the resulting merged array will also have "private" set to true. If neither has a "private" key
     * set to true, the resulting merged array will have "public" set to true if either the $base or
     * $inc array has a "public" key set to true.
     *
     * @param CcModel $base The base associative array to merge into.
     * @param CcModel $inc The associative array to merge into $base.
     */
    private static function mergePrivacy(array &$base, array $inc): void
    {
        // private beats public
        $base['privacy']['private'] = $base['privacy']['private'] || $inc['privacy']['private'];
        $base['privacy']['public'] = !$base['privacy']['private'] && ($base['privacy']['public'] || $inc['privacy']['public']);
    }

    /**
     * Returns the minimum of two integers, or null if either is null.
     *
     * This is a tiny, fast, and regex-free implementation of min() that
     * only works with integers and null. It is not suitable for general use.
     *
     * @param ?int $a The first integer to compare
     * @param ?int $b The second integer to compare
     * @return ?int The minimum of the two integers, or null if either is null
     */
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

    /**
     * Serializes the Cache-Control model into a Cache-Control header line.
     *
     * @param CcModel $m The Cache-Control model to serialize
     * @return string The serialized Cache-Control header line
     */
    private static function modelToLine(array $m): string
    {
        $parts = [];

        self::appendFlagToken($parts, (bool) $m['bools']['no-store'], 'no-store');
        self::appendFieldToken($parts, (bool) $m['bools']['no-cache'], 'no-cache', $m['fields']['no-cache']);
        self::appendPrivacyToken($parts, $m['privacy'], $m['fields']);

        foreach (['no-transform', 'must-revalidate', 'proxy-revalidate', 'immutable'] as $token) {
            self::appendFlagToken($parts, (bool) $m['bools'][$token], $token);
        }

        self::appendNumericTokens($parts, $m['nums']);
        self::appendExtensionTokens($parts, $m['ext']);

        return implode(', ', $parts);
    }

    /**
     * If immutable is set, but other Cache-Control directives that disable caching are present,
     * then set immutable to false.
     *
     * The presence of immutable means that the response is uncacheable, but other directives
     * like no-store, no-cache, must-revalidate, or proxy-revalidate would also prevent caching.
     * This method normalizes the Cache-Control directives by removing immutable if any of the above
     * directives are present.
     *
     * @param CcModel $base
     */
    private static function normalizeImmutable(array &$base): void
    {
        if (
            $base['bools']['immutable']
            && (
                $base['bools']['no-store']
                || $base['bools']['no-cache']
                || $base['bools']['must-revalidate']
                || $base['bools']['proxy-revalidate']
            )
        ) {
            $base['bools']['immutable'] = false;
        }
    }

    /**
     * Parses a Cache-Control line into an immutable, typed layout.
     * Returns an empty model when the input is empty.
     *
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
        $m = self::emptyModel();
        if ($line === '') {
            return $m;
        }

        foreach (self::splitCsvRespectingQuotes($line) as $raw) {
            self::ingestModelToken($m, $raw);
        }

        return $m;
    }

    /**
     * @param array<string, string|null> $parts
     * @return array{0: array<string, string|null>, 1: array<string, string|null>}
     */
    private static function partitionParts(array $parts): array
    {
        $std = [];
        $ext = [];
        foreach ($parts as $k => $v) {
            if (isset(self::BOOL_TOKENS[$k]) || isset(self::PRIVACY_TOKENS[$k]) || isset(self::NUM_TOKENS[$k])) {
                $std[$k] = $v;

                continue;
            }
            $ext[$k] = $v;
        }

        return [$std, $ext];
    }

    /**
     * Renders a Cache-Control header field token given a name and an array of fields.
     * If the fields array is empty, returns the name as is.
     * Otherwise, returns the name followed by an equals sign and a quoted comma-separated list of the sorted field names.
     *
     * @param string $name The name of the Cache-Control header field token.
     * @param array<string,true> $fields The fields to include in the token.
     * @return string The rendered Cache-Control header field token.
     */
    private static function renderFieldToken(string $name, array $fields): string
    {
        if ($fields === []) {
            return $name;
        }
        $keys = array_keys($fields);
        sort($keys, SORT_STRING);

        return $name . '="' . implode(', ', $keys) . '"';
    }

    /**
     * Split a comma-separated string into an array, respecting quoted strings.
     *
     * The function works by iterating through each character of the input string.
     * It keeps track of whether it is currently inside a quoted string or not.
     * When it encounters a comma outside of a quoted string, it splits the buffer and resets it.
     * When it encounters a double quote, it toggles the "in quote" flag.
     * Finally, it trims and filters out empty strings from the output array.
     *
     * @param string $line The input string to split
     * @return list<string> The resulting array of strings
     */
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

        return array_values(array_filter($out, static fn(string $s): bool => $s !== ''));
    }

    /**
     * Converts a string to an integer or null if it cannot be parsed.
     *
     * Allows strings with a leading '+' (common in HTTP headers).
     * If the string is empty or null, returns null.
     * If the string contains only digits, returns the integer value.
     * Otherwise, returns null.
     *
     * @param string|null $s The string to convert to an integer or null.
     * @return int|null The integer value of the string or null if it cannot be parsed.
     */
    private static function toIntOrNull(?string $s): ?int
    {
        if ($s === null || $s === '') {
            return null;
        }
        // allow +digits (common) and clamp to >=0
        $digits = ltrim($s, '+');

        return ctype_digit($digits) ? max(0, (int) $digits) : null;
        // (No regex for speed; this outperforms filter_var for tiny strings.)
    }

    /**
     * Return a new instance with the specified Cache-Control directive set.
     *
     *   • If the value is null, the directive is removed.
     *   • Public and private are mutually exclusive; last write wins.
     *   • Each directive is canonicalized to lowercase.
     *
     * @param string $token Valid directive name (e.g., 'max-age', 'public', etc.)
     * @param int|string|null $value New value for the directive (or null to remove)
     * @return self New instance with the specified Cache-Control directive set
     */
    private function with(string $token, int|string|null $value = null): self
    {
        $token = strtolower($token); // canonicalize once
        $x = clone $this;
        $x->parts[$token] = $value === null ? null : (string) $value;
        // public/private mutually exclusive – last write wins
        if ($token === 'public') {
            unset($x->parts['private']);
        } elseif ($token === 'private') {
            unset($x->parts['public']);
        }

        return $x;
    }
}
