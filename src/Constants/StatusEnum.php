<?php

/**
 * Webrick - HTTP status code enumeration and helpers.
 *
 * Defines a comprehensive set of HTTP status codes and provides convenience
 * methods to resolve reason phrases, determine code series, classify responses
 * (informational/success/redirect/client-error/server-error), and evaluate body
 * allowance and cacheability. Also includes static utilities for code-to-text
 * conversion and emptiness checks.
 *
 * @package Infocyph\Webrick\Constants
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Constants;

/**
 * HTTP status codes as int-backed enum with convenience helpers.
 */
enum StatusEnum: int
{
    case ACCEPTED = 202;
    case ALREADY_REPORTED = 208;
    case BAD_GATEWAY = 502;

    /* 4xx */
    case BAD_REQUEST = 400;
    case BANDWIDTH_LIMIT_EXCEEDED = 509;  // project-specific
    case CONFLICT = 409;
    /* 1xx */
    case CONTINUE = 100;
    case CREATED = 201;
    case EARLY_HINTS = 103;
    case EXPECTATION_FAILED = 417;
    case FAILED_DEPENDENCY = 424;
    case FORBIDDEN = 403;
    case FOUND = 302;
    case GATEWAY_TIMEOUT = 504;
    case GONE = 410;
    case HTTP_VERSION_NOT_SUPPORTED = 505;
    case IM_A_TEAPOT = 418;
    case IM_USED = 226;
    case INSUFFICIENT_STORAGE = 507;

    /* 5xx */
    case INTERNAL_SERVER_ERROR = 500;
    case LENGTH_REQUIRED = 411;
    case LOCKED = 423;
    case LOOP_DETECTED = 508;
    case METHOD_NOT_ALLOWED = 405;
    case MISDIRECTED_REQUEST = 421;
    case MOVED_PERMANENTLY = 301;
    case MULTI_STATUS = 207;

    /* 3xx */
    case MULTIPLE_CHOICES = 300;
    case NETWORK_AUTH_REQUIRED = 511;
    case NO_CONTENT = 204;
    case NON_AUTHORITATIVE_INFO = 203;
    case NOT_ACCEPTABLE = 406;
    case NOT_EXTENDED = 510;
    case NOT_FOUND = 404;
    case NOT_IMPLEMENTED = 501;
    case NOT_MODIFIED = 304;

    /* 2xx */
    case OK = 200;
    case PARTIAL_CONTENT = 206;
    case PAYLOAD_TOO_LARGE = 413;
    case PAYMENT_REQUIRED = 402;
    case PERMANENT_REDIRECT = 308;
    case PRECONDITION_FAILED = 412;
    case PRECONDITION_REQUIRED = 428;
    case PROCESSING = 102;
    case PROXY_AUTH_REQUIRED = 407;
    case RANGE_NOT_SATISFIABLE = 416;
    case REQUEST_HEADER_FIELDS_TOO_LARGE = 431;
    case REQUEST_TIMEOUT = 408;
    case RESET_CONTENT = 205;
    case SEE_OTHER = 303;
    case SERVICE_UNAVAILABLE = 503;
    case SWITCHING_PROTOCOLS = 101;
    case TEMPORARY_REDIRECT = 307;
    case TOO_EARLY = 425;
    case TOO_MANY_REQUESTS = 429;
    case UNAUTHORIZED = 401;
    case UNAVAILABLE_FOR_LEGAL_REASONS = 451;
    case UNPROCESSABLE_ENTITY = 422;
    case UNSUPPORTED_MEDIA_TYPE = 415;
    case UPGRADE_REQUIRED = 426;
    case URI_TOO_LONG = 414;
    case USE_PROXY = 305;
    case VARIANT_ALSO_NEGOTIATES = 506;

    /**
     * Check if the given status code is "empty" (no body).
     *
     * If not recognized, checks whether it lies in the 1xx range.
     *
     * @param int $code HTTP status code to check.
     *
     * @return bool True if empty; false otherwise.
     */
    public static function isEmptyCode(int $code): bool
    {
        return self::tryFrom($code)?->isEmpty()
            ?? ($code >= 100 && $code < 200);
    }

    /**
     * Check if a status code belongs to HTTP error ranges (4xx or 5xx).
     *
     * Unknown extension codes in the 4xx/5xx space are treated as errors too.
     */
    public static function isErrorCode(int $code): bool
    {
        return $code >= self::BAD_REQUEST->value && $code < 600;
    }

    /**
     * Check if a status code belongs to the HTTP server-error range (5xx).
     *
     * Unknown extension codes in the 5xx space are treated as server errors too.
     */
    public static function isServerErrorCode(int $code): bool
    {
        return $code >= self::INTERNAL_SERVER_ERROR->value && $code < 600;
    }

    /**
     * Get the reason phrase for the given status code.
     *
     * @param int $code HTTP status code.
     *
     * @return string Reason phrase (empty string if unknown).
     */
    public static function text(int $code): string
    {
        return self::tryFrom($code)?->reason() ?? '';
    }

    /**
     * Whether a response body is allowed for this status code.
     *
     * @return bool True if a body is allowed; false if the response is empty.
     */
    public function allowsBody(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Heuristic: whether the response is cacheable by default.
     *
     * Notes:
     * - Some codes are cacheable only when explicit Expires/Cache-Control headers exist,
     *   or when heuristics are permitted (see inline comments).
     *
     * @return bool True if cacheable by default; false otherwise.
     */
    public function isCacheable(): bool
    {
        return match ($this) {
            self::OK, self::NON_AUTHORITATIVE_INFO, self::NO_CONTENT,
            self::PARTIAL_CONTENT, self::MULTIPLE_CHOICES,
            self::MOVED_PERMANENTLY, self::GONE,
            self::UNAUTHORIZED, self::FORBIDDEN,           // if explicit Expires/Cache-Control
            self::FOUND, self::SEE_OTHER                   // if heuristics allowed
            => true,
            default => false,
        };
    }

    /**
     * Strict default-cacheable set (subset of isCacheable()).
     *
     * @return bool True if cacheable by default in stricter sense; false otherwise.
     */
    public function isCacheableByDefault(): bool
    {
        return match ($this) {
            self::OK,
            self::NON_AUTHORITATIVE_INFO,
            self::PARTIAL_CONTENT,
            self::MOVED_PERMANENTLY,
            self::GONE => true,
            default => false,
        };
    }

    /**
     * Check if the status code is client error (400–499).
     *
     * @return bool True for client errors; false otherwise.
     */
    public function isClientError(): bool
    {
        return $this->series() === 4;
    }

    /**
     * Check if the response is considered "empty" (no body).
     *
     * Empty responses include:
     * - 1xx (except 101 Switching Protocols)
     * - 204 No Content
     * - 205 Reset Content
     * - 304 Not Modified
     *
     * @return bool True if empty; false otherwise.
     */
    public function isEmpty(): bool
    {
        return ($this->isInformational() && $this !== self::SWITCHING_PROTOCOLS)
            || $this === self::NO_CONTENT
            || $this === self::RESET_CONTENT
            || $this === self::NOT_MODIFIED;
    }

    /**
     * Check if the status code is informational (100–199).
     *
     * @return bool True for informational; false otherwise.
     */
    public function isInformational(): bool
    {
        return $this->series() === 1;
    }

    /**
     * Check if the status code is redirect (300–399).
     *
     * @return bool True for redirects; false otherwise.
     */
    public function isRedirect(): bool
    {
        return $this->series() === 3;
    }

    /**
     * Check if the status code is server error (500–599).
     *
     * @return bool True for server errors; false otherwise.
     */
    public function isServerError(): bool
    {
        return $this->series() === 5;
    }

    /**
     * Check if the status code is success (200–299).
     *
     * @return bool True for success; false otherwise.
     */
    public function isSuccess(): bool
    {
        return $this->series() === 2;
    }

    /**
     * Whether a Location header is expected (201 Created or any 3xx).
     *
     * @return bool True if Location should be included; false otherwise.
     */
    public function needsLocationHeader(): bool
    {
        return $this === self::CREATED || $this->isRedirect();
    }

    /**
     * Get the human-readable reason phrase for this status code.
     *
     * Falls back to a derived phrase from the enum name when not explicitly listed.
     *
     * @return string Reason phrase for the status code (empty string if unknown).
     */
    public function reason(): string
    {
        /* ① Irregular spellings we can’t derive automatically */
        static $irregular = [
            self::MULTI_STATUS->value => 'Multi-Status',
            self::NON_AUTHORITATIVE_INFO->value => 'Non-Authoritative Information',
            self::IM_A_TEAPOT->value => "I'm a teapot",
            self::NETWORK_AUTH_REQUIRED->value => 'Network Authentication Required',
        ];

        /* ② Per-worker memoisation for the regular cases */
        static $cache = [];         // int code → string phrase

        $code = $this->value;

        // Fast-path hits: irregular table or memo cache
        if (isset($irregular[$code])) {
            return $irregular[$code];
        }
        if (isset($cache[$code])) {
            return $cache[$code];
        }

        /* ③ First time we see this code → derive & memoise */
        return $cache[$code] = ucwords(
            strtolower(str_replace('_', ' ', $this->name)),
        );
    }

    /**
     * Return the status code series (1xx, 2xx, 3xx, etc.) as an integer.
     *
     * @return int Series of the status code (e.g., 2 for 2xx).
     */
    public function series(): int
    {
        return intdiv($this->value, 100);
    }
}
