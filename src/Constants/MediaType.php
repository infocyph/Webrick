<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Constants;

enum MediaType: string
{
    /* ------------ canonical cases ------------ */
    // generic
    case OCTET = 'application/octet-stream';
    case HTML = 'text/html; charset=utf-8';
    case PLAIN = 'text/plain; charset=utf-8';
    case CSV = 'text/csv; charset=utf-8';
    case JSON = 'application/json';
    case XML = 'application/xml';
    case JPEG = 'image/jpeg';
    case PNG = 'image/png';
    case GIF = 'image/gif';
    case WEBP = 'image/webp';
    case SVG = 'image/svg+xml';
    case AVIF = 'image/avif';
    case PDF = 'application/pdf';
    case JAVASCRIPT = 'text/javascript';          // or 'application/javascript' if you prefer
    case MANIFEST_JSON = 'application/manifest+json';
    case WASM = 'application/wasm';
    case NDJSON = 'application/x-ndjson';

    /**
     * Resolve a MediaType enum case from a file extension.
     *
     * @param string $ext The file extension (e.g. "jpg", "json", ...).
     * @return self The resolved MediaType enum case.
     */
    public static function fromExtension(string $ext): self
    {
        /* ---- static 1: irregular aliases (opcode-cached) ---- */
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

        /* ---- static 2: resolved memo cache  ----------------- */
        static $cache = [];

        $ext = strtolower($ext);
        if (isset($cache[$ext])) {
            return $cache[$ext];
        }

        $key = $alias[$ext] ?? $ext;       // 'jpg' → 'jpeg'
        $caseName = strtoupper($key);
        $case = defined(self::class . '::' . $caseName)
            ? constant(self::class . '::' . $caseName)
            : self::OCTET;

        return $cache[$ext] = $case;
    }

    /** Convenience – resolve from a whole filename. */
    public static function fromFilename(string $file): self
    {
        return ($dot = strrpos($file, '.')) === false
            ? self::OCTET
            : self::fromExtension(substr($file, $dot + 1));
    }

    /* --------------------------------------------------
     * 2)  Misc helpers
     * ------------------------------------------------- */
    public function isTextual(): bool
    {
        return str_starts_with($this->value, 'text/')
            || $this === self::JSON
            || $this === self::XML;
    }

    public function charset(): ?string
    {
        return preg_match('/charset=([^;]+)/i', $this->value, $m) ? $m[1] : null;
    }

    public function header(): string
    {
        return $this->value;
    }
}
