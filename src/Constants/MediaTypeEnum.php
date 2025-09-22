<?php

/**
 * Webrick - Media (MIME) type enumeration and helpers.
 *
 * Defines canonical media types used across the Webrick stack and provides utilities
 * to resolve a media type from file extensions or filenames, as well as helpers for
 * textual classification and header/charset retrieval.
 *
 * @package Infocyph\Webrick\Constants
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Constants;

/**
 * Canonical media (MIME) types with convenience helpers.
 *
 * Includes commonly used textual, image, and application types. Utility methods
 * support resolution by extension/filename, textual checks, and header formatting.
 */
enum MediaTypeEnum: string
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
     * - Handles irregular aliases (e.g., "jpg" → "jpeg", "htm" → "html").
     * - Uses a small memo cache for repeat lookups.
     * - Defaults to MediaType::OCTET when unknown.
     *
     * @param string $ext File extension (e.g., "jpg", "json").
     *
     * @return self Resolved MediaType enum case.
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

    /**
     * Resolve a MediaType enum case from a file name.
     *
     * Finds the last '.' in the file name and resolves from the extension part.
     * If there is no '.', defaults to MediaType::OCTET.
     *
     * @param string $file File name (e.g., "example.jpg", "document.json").
     *
     * @return self Resolved MediaType enum case.
     */
    public static function fromFilename(string $file): self
    {
        return ($dot = strrpos($file, '.')) === false
            ? self::OCTET
            : self::fromExtension(substr($file, $dot + 1));
    }

    /**
     * Extract the character set from the media type header value.
     *
     * Example:
     * - "text/html; charset=utf-8" → "utf-8"
     *
     * @return string|null The charset value, or null if no charset parameter is present.
     */
    public function charset(): ?string
    {
        return preg_match('/charset=([^;]+)/i', $this->value, $m) ? $m[1] : null;
    }

    /**
     * Get the HTTP header value for this media type.
     *
     * @return string Header-ready media type string.
     */
    public function header(): string
    {
        return $this->value;
    }

    /**
     * Determine whether the media type is textual.
     *
     * A type is considered textual when it starts with "text/" or equals JSON/XML.
     *
     * @return bool True if textual; false otherwise.
     */
    public function isTextual(): bool
    {
        return str_starts_with($this->value, 'text/')
            || $this === self::JSON
            || $this === self::XML;
    }
}
