<?php

/**
 * Webrick - Media (MIME) type enumeration and helpers.
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Constants;

/** Canonical media (MIME) types with convenience helpers. */
enum MediaTypeEnum: string
{
    case AVIF = 'image/avif';

    case CSV = 'text/csv; charset=utf-8';

    case FORM_URLENCODED = 'application/x-www-form-urlencoded';

    case GIF = 'image/gif';

    case HTML = 'text/html; charset=utf-8';

    case JAVASCRIPT = 'text/javascript';

    case JPEG = 'image/jpeg';

    case JSON = 'application/json';

    case MANIFEST_JSON = 'application/manifest+json';

    case MULTIPART_FORM_DATA = 'multipart/form-data';

    case NDJSON = 'application/x-ndjson';

    case OCTET = 'application/octet-stream';

    case PDF = 'application/pdf';

    case PLAIN = 'text/plain; charset=utf-8';

    case PNG = 'image/png';

    case PROBLEM_JSON = 'application/problem+json';

    case SVG = 'image/svg+xml';

    case WASM = 'application/wasm';

    case WEBP = 'image/webp';

    case XML = 'application/xml';

    public static function fromExtension(string $ext): self
    {
        /** @var array<string,string> $alias */
        static $alias = [
            'jpg' => 'jpeg',
            'htm' => 'html',
            'svgz' => 'svg',
            'txt' => 'plain',
            'md' => 'plain',
            'webmanifest' => 'manifest_json',
            'mjs' => 'javascript',
            'js' => 'javascript',
            'wasm' => 'wasm',
            'ndjson' => 'ndjson',
            'jsonl' => 'ndjson',
        ];

        /** @var array<string,self> $map */
        static $map = [
            'avif' => self::AVIF,
            'csv' => self::CSV,
            'gif' => self::GIF,
            'html' => self::HTML,
            'javascript' => self::JAVASCRIPT,
            'jpeg' => self::JPEG,
            'json' => self::JSON,
            'manifest_json' => self::MANIFEST_JSON,
            'ndjson' => self::NDJSON,
            'octet' => self::OCTET,
            'pdf' => self::PDF,
            'plain' => self::PLAIN,
            'png' => self::PNG,
            'problem_json' => self::PROBLEM_JSON,
            'svg' => self::SVG,
            'wasm' => self::WASM,
            'webp' => self::WEBP,
            'xml' => self::XML,
        ];

        $ext = strtolower($ext);
        $key = $alias[$ext] ?? $ext;

        return $map[$key] ?? self::OCTET;
    }

    public static function fromFilename(string $file): self
    {
        $dot = strrpos($file, '.');
        if ($dot === false) {
            return self::OCTET;
        }

        return self::fromExtension(substr($file, $dot + 1));
    }

    public static function isJsonLike(string $type): bool
    {
        $base = strtolower(trim(explode(';', $type, 2)[0]));

        return $base === self::JSON->base() || str_ends_with($base, '+json');
    }

    public static function isXmlLike(string $type): bool
    {
        $base = strtolower(trim(explode(';', $type, 2)[0]));

        return $base === self::XML->base() || $base === 'text/xml' || str_ends_with($base, '+xml');
    }

    public function base(): string
    {
        $parts = explode(';', $this->value, 2);

        return strtolower(trim($parts[0]));
    }

    public function charset(): ?string
    {
        return preg_match('/charset=([^;]+)/i', $this->value, $m) ? trim($m[1], " \t\n\r\0\x0B\"") : null;
    }

    public function header(): string
    {
        return $this->value;
    }

    public function isTextual(): bool
    {
        $base = $this->base();

        return str_starts_with($base, 'text/')
            || self::isJsonLike($base)
            || self::isXmlLike($base);
    }
}
