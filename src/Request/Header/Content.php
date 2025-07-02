<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Header;

use Infocyph\ArrayKit\Collection\Collection;

/**
 * Parsed `Content-*` helpers (type, charset, length, md5).
 */
final readonly class Content
{
    private string $rawType;
    private string $rawLength;
    private string $rawMd5;

    public function __construct(string $type, string $length, string $md5)
    {
        $this->rawType   = $type;
        $this->rawLength = $length;
        $this->rawMd5    = $md5;
    }

    public function type(): ?string
    {
        [$type] = \explode(';', \strtolower($this->rawType), 2);
        return $type !== '' ? $type : null;
    }

    public function charset(): ?string
    {
        return \preg_match('/charset=([^;]+)/i', $this->rawType, $m)
            ? \trim($m[1])
            : null;
    }

    public function length(): ?int
    {
        return \is_numeric($this->rawLength) ? (int) $this->rawLength : null;
    }

    public function md5(): ?string
    {
        return $this->rawMd5 !== '' ? \strtolower($this->rawMd5) : null;
    }

    /** Shorthand bundle for `$request->headers()->content()->toCollection()` */
    public function toCollection(): Collection
    {
        return Collection::from([
            'type'    => $this->type(),
            'charset' => $this->charset(),
            'length'  => $this->length(),
            'md5'     => $this->md5(),
        ]);
    }
}
