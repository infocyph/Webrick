<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Core;

/**
 * Lean PSR-7 message base-class:
 *  • Immutable  (`with*()` clones)
 *  • Normalises header names once (ucwords-dashed)
 *  • Re-uses Core\Stream for the body
 *
 *  NOTE: kept internal-final – only ServerRequest / Response extend it.
 */
abstract class Message
{
    /* ---------------- state ---------------- */
    protected string $protocol = '1.1';
    protected array $headers = [];   // ["Host" => ["example.com"]]
    protected Stream $body;

    /* ---------------- ctor ----------------- */
    protected function __construct(array $hdr = [], ?Stream $body = null, string $proto = '1.1')
    {
        $this->headers = $this->normalise($hdr);
        $this->body = $body ?? new Stream();
        $this->protocol = $proto;
    }

    /* ===== PSR-7: protocol ===== */
    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    public function withProtocolVersion($v): static
    {
        if ($v === $this->protocol) {
            return $this;
        }
        $c = clone $this;
        $c->protocol = $v;
        return $c;
    }

    /* ===== PSR-7: headers ===== */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader($name): bool
    {
        return isset($this->headers[$this->norm($name)]);
    }

    public function getHeader($name): array
    {
        return $this->headers[$this->norm($name)] ?? [];
    }

    public function getHeaderLine($name): string
    {
        return implode(',', $this->getHeader($name));
    }

    public function withHeader($n, $v): static
    {
        $norm = $this->norm($n);
        $val = is_array($v) ? array_values($v) : [(string)$v];
        if (($this->headers[$norm] ?? null) === $val) {
            return $this;
        }
        $c = clone $this;
        $c->headers[$norm] = $val;
        return $c;
    }

    public function withAddedHeader($n, $v): static
    {
        $norm = $this->norm($n);
        $val = is_array($v) ? $v : [(string)$v];
        if (!$this->hasHeader($norm)) {
            return $this->withHeader($norm, $val);
        }
        if ($val === array_intersect($val, $this->headers[$norm])) {
            return $this;                       // already present
        }
        $c = clone $this;
        $c->headers[$norm] = array_merge($this->headers[$norm], $val);
        return $c;
    }

    public function withoutHeader($n): static
    {
        if (!$this->hasHeader($n)) {
            return $this;
        }
        $c = clone $this;
        unset($c->headers[$this->norm($n)]);
        return $c;
    }

    /* ===== PSR-7: body ===== */
    public function getBody(): Stream
    {
        return $this->body;
    }

    public function withBody(Stream $b): static
    {
        if ($b === $this->body) {
            return $this;
        }
        $c = clone $this;
        $c->body = $b;
        return $c;
    }

    /* ---------------- internals ---------------- */
    private function norm(string $n): string
    {
        static $cache = [];
        return $cache[$n] ??= ucwords(strtolower($n), '-');
    }

    private function normalise(array $h): array
    {
        $out = [];
        foreach ($h as $k => $v) {
            $out[$this->norm($k)] = is_array($v) ? array_values($v) : [(string)$v];
        }
        return $out;
    }

    /* guard: subclasses only */
    protected function __clone(): void {}
}
