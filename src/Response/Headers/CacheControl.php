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
            $base['nums'][$name] = self::minInt($base['nums'][$name], $incoming['nums'][$name]);
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
    private static function ingestToken(array &$model, string $raw): void
    {
        $token = trim($raw);
        if ($token === '') {
            return;
        }

        $position = strpos($token, '=');
        if ($position === false) {
            $name = strtolower($token);
            if (isset(self::PRIVACY_TOKENS[$name])) {
                $model['privacy'][$name] = true;
            } elseif (isset(self::BOOL_TOKENS[$name])) {
                $model['bools'][$name] = true;
            } elseif (!array_key_exists($name, $model['ext'])) {
                $model['ext'][$name] = null;
            }

            return;
        }

        $name = strtolower(trim(substr($token, 0, $position)));
        $value = trim(trim(substr($token, $position + 1)), "\"'");

        if (isset(self::NUM_TOKENS[$name])) {
            $delta = self::toDeltaSeconds($value);
            if ($delta !== null) {
                $model['nums'][$name] = self::minInt($model['nums'][$name], $delta);
            }

            return;
        }

        if (isset(self::FIELD_TOKENS[$name])) {
            if ($name === 'private') {
                $model['privacy']['private'] = true;
            } else {
                $model['bools']['no-cache'] = true;
            }
            if ($value !== '') {
                self::ingestFields($model['fields'][$name], $value);
            }

            return;
        }

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

    private static function minInt(?int $a, ?int $b): ?int
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return min($a, $b);
    }

    /** @param array{bools:array<string,bool>,privacy:array{public:bool,private:bool},nums:array<string,?int>,fields:array<string,array<string,true>>,ext:array<string,string|null>} $model */
    private static function modelToLine(array $model): string
    {
        $parts = [];
        if ($model['bools']['no-store']) {
            $parts[] = 'no-store';
        }
        if ($model['bools']['no-cache']) {
            $parts[] = self::renderFieldToken('no-cache', $model['fields']['no-cache']);
        }
        if ($model['privacy']['private']) {
            $parts[] = self::renderFieldToken('private', $model['fields']['private']);
        } elseif ($model['privacy']['public']) {
            $parts[] = 'public';
        }
        foreach (['no-transform', 'must-revalidate', 'proxy-revalidate', 'immutable'] as $name) {
            if ($model['bools'][$name]) {
                $parts[] = $name;
            }
        }
        foreach (self::NUM_TOKENS as $name => $_) {
            if ($model['nums'][$name] !== null) {
                $parts[] = $name . '=' . $model['nums'][$name];
            }
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
        foreach (self::splitCsvRespectingQuotes($line) as $token) {
            self::ingestToken($model, $token);
        }

        return $model;
    }

    /** @return array<string,string|null> */
    private static function partsFromLine(string $line): array
    {
        $parts = [];
        foreach (self::splitCsvRespectingQuotes($line) as $raw) {
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

    /** @param array<string,string|null> $parts @return array{0:array<string,string|null>,1:array<string,string|null>} */
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

    /** @return list<string> */
    private static function splitCsvRespectingQuotes(string $line): array
    {
        if ($line === '') {
            return [];
        }

        $out = [];
        $buffer = '';
        $quoted = false;
        $escaped = false;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($escaped) {
                $buffer .= $char;
                $escaped = false;

                continue;
            }
            if ($quoted && $char === '\\') {
                $buffer .= $char;
                $escaped = true;

                continue;
            }
            if ($char === '"') {
                $quoted = !$quoted;
                $buffer .= $char;

                continue;
            }
            if ($char === ',' && !$quoted) {
                $token = trim($buffer);
                if ($token !== '') {
                    $out[] = $token;
                }
                $buffer = '';

                continue;
            }
            $buffer .= $char;
        }
        $token = trim($buffer);
        if ($token !== '') {
            $out[] = $token;
        }

        return $out;
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
