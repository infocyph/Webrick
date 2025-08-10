<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Constants;

/**
 * Minimal MIME registry – alias-aware, *zero* loops.
 *
 * ➊  canonical media-types are enum cases
 * ➋  `fromExtension()` resolves the case by:
 *       • alias-table fast-path (`jpg` → `jpeg`, …)
 *       • constant-exists check (`defined()`) – O(1)
 *       • per-worker memo cache – subsequent calls are
 *         one hash-lookup and a return.
 */
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

    /* --------------------------------------------------
     * 1)  Extension → MediaType
     * ------------------------------------------------- */
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
        static $cache = [];                // 'json' => MediaType::JSON

        $ext = strtolower($ext);

        /* fast memo hit */
        if (isset($cache[$ext])) {
            return $cache[$ext];
        }

        /* ① normalise via alias table */
        $key = $alias[$ext] ?? $ext;       // 'jpg' → 'jpeg'

        /* ② build the would-be case name (HTML, JSON, …) */
        $caseName = strtoupper($key);

        /* ③ constant lookup – O(1); no reflection, no loops */
        if (defined(self::class . '::' . $caseName)) {
            /** @var self $case */
            $case = constant(self::class . '::' . $caseName);
        } else {
            $case = self::OCTET;
        }

        /* ④ memoise & return */
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
