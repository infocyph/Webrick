<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Headers;

use Infocyph\Webrick\Request\Support\HeaderBag;

/** Immutable builder and restrictive merger for Cache-Control. */
final class CacheControl implements \Stringable
{
    private const array BOOL_TOKENS = [
        'no-store' => true,
        'no-cache' => true,
        'no-transform' => true,
        'must-revalidate' => true,
        'proxy-revalidate' => true,
        'immutable' => true,
    ];

    private const array FIELD_TOKENS = ['no-cache' => true, 'private' => true];

    private const array NUM_TOKENS = [
        'max-age' => true,
        's-maxage' => true,
        'stale-while-revalidate' => true,
        'stale-if-error' => true,
    ];

    private const array PRIVACY_TOKENS = ['public' => true, 'private' => true];

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

    /** @param array<string,string|null> $parts */
    private function __construct(private array $parts = []) {}

    public function __toString(): string
    {
        [$standard, $extensions] = self::partitionParts($this->parts);
        $out = [];
        foreach (self::RENDER_ORDER as $name) {
            if (!array_key_exists($name, $standard)) {
                continue;
            }
            $value = $standard[$name];
            $out[] = $value === null ? $name : $name . '=' . $value;
        }
        if ($extensions !== []) {
            ksort($extensions);
            foreach ($extensions as $name => $value) {
                $out[] = $value === null ? $name : $name . '=' . $value;
            }
        }

        return implode(', ', $out);
    }

    public static function canonicalizeMerge(string $existingLine, string $incomingLine): string
    {
        $base = self::parseModel($existingLine);
        $incoming = self::parseModel($incomingLine);

        foreach (self::BOOL_TOKENS as $name => $_) {
            $base['bools'][$name] = $base['bools'][$name] || $incoming['bools'][$name];
        }

        $base['privacy']['private'] = $base['privacy']['private'] || $incoming['privacy']['private'];
        $base['privacy']['public'] = !$base['privacy']['private']
            && ($base['privacy']['public'] || $incoming['privacy']['public']);

        foreach (self::NUM_TOKENS as $name => $_) {
            $base['nums'][$name] = CacheControlNumber::min($base['nums'][$name], $incoming['nums'][$name]);
        }

        foreach (['no-cache', 'private'] as $name) {
            $base['fields'][$name] += $incoming['fields'][$name];
        }
        if ($base['fields']['no-cache'] !== [] || $incoming['bools']['no-cache']) {
            $base['bools']['no-cache'] = true;
        }
        if ($base['fields']['private'] !== [] || $incoming['privacy']['private']) {
            $base['privacy']['private'] = true;
            $base['privacy']['public'] = false;
        }

        $base['ext'] = $incoming['ext'] + $base['ext'];
        self::normalizeModel($base);

        return self::modelToLine($base);
    }

    /** @return array<string,true|string> */
    public static function directives(string $line): array
    {
        $directives = [];
        foreach (CacheControlCsv::split($line) as $raw) {
            $token = trim($raw);
            if ($token === '') {
                continue;
            }

            $position = strpos($token, '=');
            if ($position === false) {
                $directives[strtolower($token)] = true;

                continue;
            }

            $name = strtolower(trim(substr($token, 0, $position)));
            if ($name === '') {
                continue;
            }
            $value = trim(substr($token, $position + 1));
            if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
                $value = substr($value, 1, -1);
            }
            $directives[$name] = $value;
        }

        return $directives;
    }

    public static function fromHeaderBag(HeaderBag $bag): self
    {
        $line = $bag->getHeaderLine('Cache-Control');
        if ($line === '') {
            return new self();
        }

        $model = self::parseModel($line);
        self::normalizeModel($model);

        return new self(self::partsFromLine(self::modelToLine($model)));
    }

    public static function new(): self
    {
        return new self();
    }

    public function immutable(): self
    {
        return $this->with('immutable');
    }

    public function maxAge(int $seconds): self
    {
        return $this->with('max-age', $seconds);
    }

    public function mustRevalidate(): self
    {
        return $this->with('must-revalidate');
    }

    public function noCache(): self
    {
        return $this->with('no-cache');
    }

    public function noStore(): self
    {
        return $this->with('no-store');
    }

    public function private(): self
    {
        return $this->with('private');
    }

    public function proxyRevalidate(): self
    {
        return $this->with('proxy-revalidate');
    }

    public function public(): self
    {
        return $this->with('public');
    }

    public function sMaxAge(int $seconds): self
    {
        return $this->with('s-maxage', $seconds);
    }

    public function staleIfError(int $seconds): self
    {
        return $this->with('stale-if-error', $seconds);
    }

    public function staleWhileRevalidate(int $seconds): self
    {
        return $this->with('stale-while-revalidate', $seconds);
    }

    /** @param list<string> $parts */
    private static function appendIfTrue(array &$parts, bool $condition, string $part): void
    {
        if ($condition) {
            $parts[] = $part;
        }
    }

    /** @param list<string> $parts */
    private static function appendNumericPart(array &$parts, string $name, ?int $value): void
    {
        if ($value !== null) {
            $parts[] = $name . '=' . $value;
        }
    }

    /** @return array{bools:array<string,bool>,privacy:array{public:bool,private:bool},nums:array<string,?int>,fields:array<string,array<string,true>>,ext:array<string,string|null>} */
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

    /** @return list<string> */
    private static function emptyParts(): array
    {
        return [];
    }

    /** @param array{bools:array<string,bool>,privacy:array{public:bool,private:bool},nums:array<string,?int>,fields:array<string,array<string,true>>,ext:array<string,string|null>} $model */
    private static function ingestAssignedToken(array &$model, string $name, string $value): void
    {
        if (isset(self::NUM_TOKENS[$name])) {
            $delta = self::toDeltaSeconds($value);
            if ($delta !== null) {
                $model['nums'][$name] = CacheControlNumber::min($model['nums'][$name], $delta);
            }

            return;
        }
        if (isset(self::FIELD_TOKENS[$name])) {
            self::ingestFieldToken($model, $name, $value);

            return;
        }
        self::ingestExtensionOrBoolean($model, $name, $value);
    }

    /** @param array{bools:array<string,bool>,privacy:array{public:bool,private:bool},nums:array<string,?int>,fields:array<string,array<string,true>>,ext:array<string,string|null>} $model */
    private static function ingestBareToken(array &$model, string $name): void
    {
        self::ingestExtensionOrBoolean($model, $name, null);
    }

    /** @param array{bools:array<string,bool>,privacy:array{public:bool,private:bool},nums:array<string,?int>,fields:array<string,array<string,true>>,ext:array<string,string|null>} $model */
    private static function ingestExtensionOrBoolean(array &$model, string $name, ?string $value): void
    {
        if (isset(self::PRIVACY_TOKENS[$name])) {
            $model['privacy'][$name] = true;

            return;
        }
        if (isset(self::BOOL_TOKENS[$name])) {
            $model['bools'][$name] = true;

            return;
        }
        if ($name !== '' && !array_key_exists($name, $model['ext'])) {
            $model['ext'][$name] = $value;
        }
    }

    /** @param array<string,true> $target */
    private static function ingestFields(array &$target, string $csv): void
    {
        foreach (explode(',', $csv) as $field) {
            $field = trim($field);
            if ($field !== '') {
                $target[$field] = true;
            }
        }
    }

    /** @param array{bools:array<string,bool>,privacy:array{public:bool,private:bool},nums:array<string,?int>,fields:array<string,array<string,true>>,ext:array<string,string|null>} $model */
    private static function ingestFieldToken(array &$model, string $name, string $value): void
    {
        if ($name === 'private') {
            $model['privacy']['private'] = true;
        } else {
            $model['bools']['no-cache'] = true;
        }
        if ($value !== '') {
            self::ingestFields($model['fields'][$name], $value);
        }
    }

    /** @param array{bools:array<string,bool>,privacy:array{public:bool,private:bool},nums:array<string,?int>,fields:array<string,array<string,true>>,ext:array<string,string|null>} $model */
    private static function ingestToken(array &$model, string $raw): void
    {
        $token = trim($raw);
        if ($token === '') {
            return;
        }

        $position = strpos($token, '=');
        if ($position === false) {
            self::ingestBareToken($model, strtolower($token));

            return;
        }

        self::ingestAssignedToken(
            $model,
            strtolower(trim(substr($token, 0, $position))),
            trim(trim(substr($token, $position + 1)), "\"'"),
        );
    }

    /** @param array{bools:array<string,bool>,privacy:array{public:bool,private:bool},nums:array<string,?int>,fields:array<string,array<string,true>>,ext:array<string,string|null>} $model */
    private static function modelToLine(array $model): string
    {
        $parts = self::standardParts($model);
        foreach (['no-transform', 'must-revalidate', 'proxy-revalidate', 'immutable'] as $name) {
            self::appendIfTrue($parts, $model['bools'][$name], $name);
        }
        foreach (self::NUM_TOKENS as $name => $_) {
            self::appendNumericPart($parts, $name, $model['nums'][$name]);
        }
        if ($model['ext'] !== []) {
            ksort($model['ext']);
            foreach ($model['ext'] as $name => $value) {
                $parts[] = $value === null ? $name : $name . '=' . $value;
            }
        }

        return implode(', ', $parts);
    }

    /** @param array{bools:array<string,bool>,privacy:array{public:bool,private:bool},nums:array<string,?int>,fields:array<string,array<string,true>>,ext:array<string,string|null>} $model */
    private static function normalizeModel(array &$model): void
    {
        if ($model['privacy']['private']) {
            $model['privacy']['public'] = false;
        }
        if ($model['bools']['no-store']) {
            foreach (self::NUM_TOKENS as $name => $_) {
                $model['nums'][$name] = null;
            }
            $model['bools']['immutable'] = false;
        }
        if (
            $model['bools']['immutable']
            && ($model['bools']['no-cache'] || $model['bools']['must-revalidate'] || $model['bools']['proxy-revalidate'])
        ) {
            $model['bools']['immutable'] = false;
        }
    }

    /** @return array{bools:array<string,bool>,privacy:array{public:bool,private:bool},nums:array<string,?int>,fields:array<string,array<string,true>>,ext:array<string,string|null>} */
    private static function parseModel(string $line): array
    {
        $model = self::emptyModel();
        foreach (CacheControlCsv::split($line) as $token) {
            self::ingestToken($model, $token);
        }

        return $model;
    }

    /**
     * @param array<string,string|null> $parts
     * @return array{0:array<string,string|null>,1:array<string,string|null>}
     */
    private static function partitionParts(array $parts): array
    {
        $standard = [];
        $extensions = [];
        foreach ($parts as $name => $value) {
            if (isset(self::BOOL_TOKENS[$name]) || isset(self::PRIVACY_TOKENS[$name]) || isset(self::NUM_TOKENS[$name])) {
                $standard[$name] = $value;
            } else {
                $extensions[$name] = $value;
            }
        }

        return [$standard, $extensions];
    }

    /** @return array<string,string|null> */
    private static function partsFromLine(string $line): array
    {
        $parts = [];
        foreach (CacheControlCsv::split($line) as $raw) {
            $token = trim($raw);
            if ($token === '') {
                continue;
            }
            $position = strpos($token, '=');
            if ($position === false) {
                $parts[strtolower($token)] = null;

                continue;
            }
            $name = strtolower(trim(substr($token, 0, $position)));
            if ($name !== '') {
                $parts[$name] = trim(substr($token, $position + 1));
            }
        }

        return $parts;
    }

    /** @param array<string,true> $fields */
    private static function renderFieldToken(string $name, array $fields): string
    {
        if ($fields === []) {
            return $name;
        }
        $names = array_keys($fields);
        sort($names, SORT_STRING);

        return $name . '="' . implode(', ', $names) . '"';
    }

    /**
     * @param array{bools:array<string,bool>,privacy:array{public:bool,private:bool},nums:array<string,?int>,fields:array<string,array<string,true>>,ext:array<string,string|null>} $model
     * @return list<string>
     */
    private static function standardParts(array $model): array
    {
        $parts = self::emptyParts();
        self::appendIfTrue($parts, $model['bools']['no-store'], 'no-store');
        self::appendIfTrue($parts, $model['bools']['no-cache'], self::renderFieldToken('no-cache', $model['fields']['no-cache']));
        $privacy = $model['privacy']['private']
            ? self::renderFieldToken('private', $model['fields']['private'])
            : ($model['privacy']['public'] ? 'public' : null);
        if ($privacy !== null) {
            $parts[] = $privacy;
        }

        return $parts;
    }

    private static function toDeltaSeconds(string $value): ?int
    {
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $number === false ? null : (int) $number;
    }

    private function with(string $token, int|string|null $value = null): self
    {
        $token = strtolower($token);
        if (isset(self::NUM_TOKENS[$token]) && (!is_int($value) || $value < 0)) {
            throw new \InvalidArgumentException('Cache-Control delta-seconds must be an integer >= 0.');
        }

        $copy = clone $this;
        $copy->parts[$token] = $value === null ? null : (string) $value;
        if ($token === 'public') {
            unset($copy->parts['private']);
        } elseif ($token === 'private') {
            unset($copy->parts['public']);
        }

        return $copy;
    }
}
