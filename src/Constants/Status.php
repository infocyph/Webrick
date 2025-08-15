<?php

// src/Http/Status.php
declare(strict_types=1);

namespace Infocyph\Webrick\Constants;

/**
 * Typed HTTP status codes with rich helpers.
 *
 *  ── new helpers ─────────────────────────────────────────────
 *  • series()            → 1, 2, 3, 4, 5              (int)
 *  • isInformational()   1xx                          (bool)
 *  • isSuccess()         2xx                          (bool)
 *  • isRedirect()        3xx                          (bool)
 *  • isClientError()     4xx                          (bool)
 *  • isServerError()     5xx                          (bool)
 *  • isCacheable()       RFC-defined default cacheability
 *  • allowsBody()        ! isEmpty()                  (bool)
 */
enum Status: int
{
    /* 1xx */
    case CONTINUE = 100;
    case SWITCHING_PROTOCOLS = 101;
    case PROCESSING = 102;
    case EARLY_HINTS = 103;

    /* 2xx */
    case OK = 200;
    case CREATED = 201;
    case ACCEPTED = 202;
    case NON_AUTHORITATIVE_INFO = 203;
    case NO_CONTENT = 204;
    case RESET_CONTENT = 205;
    case PARTIAL_CONTENT = 206;
    case MULTI_STATUS = 207;
    case ALREADY_REPORTED = 208;
    case IM_USED = 226;

    /* 3xx */
    case MULTIPLE_CHOICES = 300;
    case MOVED_PERMANENTLY = 301;
    case FOUND = 302;
    case SEE_OTHER = 303;
    case NOT_MODIFIED = 304;
    case USE_PROXY = 305;
    case TEMPORARY_REDIRECT = 307;
    case PERMANENT_REDIRECT = 308;

    /* 4xx */
    case BAD_REQUEST = 400;
    case UNAUTHORIZED = 401;
    case PAYMENT_REQUIRED = 402;
    case FORBIDDEN = 403;
    case NOT_FOUND = 404;
    case METHOD_NOT_ALLOWED = 405;
    case NOT_ACCEPTABLE = 406;
    case PROXY_AUTH_REQUIRED = 407;
    case REQUEST_TIMEOUT = 408;
    case CONFLICT = 409;
    case GONE = 410;
    case LENGTH_REQUIRED = 411;
    case PRECONDITION_FAILED = 412;
    case PAYLOAD_TOO_LARGE = 413;
    case URI_TOO_LONG = 414;
    case UNSUPPORTED_MEDIA_TYPE = 415;
    case RANGE_NOT_SATISFIABLE = 416;
    case EXPECTATION_FAILED = 417;
    case IM_A_TEAPOT = 418;
    case MISDIRECTED_REQUEST = 421;
    case UNPROCESSABLE_ENTITY = 422;
    case LOCKED = 423;
    case FAILED_DEPENDENCY = 424;
    case TOO_EARLY = 425;
    case UPGRADE_REQUIRED = 426;
    case PRECONDITION_REQUIRED = 428;
    case TOO_MANY_REQUESTS = 429;
    case REQUEST_HEADER_FIELDS_TOO_LARGE = 431;
    case UNAVAILABLE_FOR_LEGAL_REASONS = 451;

    /* 5xx */
    case INTERNAL_SERVER_ERROR = 500;
    case NOT_IMPLEMENTED = 501;
    case BAD_GATEWAY = 502;
    case SERVICE_UNAVAILABLE = 503;
    case GATEWAY_TIMEOUT = 504;
    case HTTP_VERSION_NOT_SUPPORTED = 505;
    case VARIANT_ALSO_NEGOTIATES = 506;
    case INSUFFICIENT_STORAGE = 507;
    case LOOP_DETECTED = 508;
    case BANDWIDTH_LIMIT_EXCEEDED = 509;  // project-specific
    case NOT_EXTENDED = 510;
    case NETWORK_AUTH_REQUIRED = 511;

    /* ─────────────────────────────── helpers ─────────────────────────── */

    /** Canonical reason-phrase. */
    /** RFC-conform reason phrase (never empty). */
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


    /** 1, 2, 3, 4 or 5 – handy for switch-statements. */
    public function series(): int
    {
        return intdiv($this->value, 100);
    }

    /* ── category helpers ─────────────────────────────────────────────── */
    public function isInformational(): bool
    {
        return $this->series() === 1;
    }

    public function isSuccess(): bool
    {
        return $this->series() === 2;
    }

    public function isRedirect(): bool
    {
        return $this->series() === 3;
    }

    public function isClientError(): bool
    {
        return $this->series() === 4;
    }

    public function isServerError(): bool
    {
        return $this->series() === 5;
    }

    /* ── entity-body semantics ────────────────────────────────────────── */
    public function isEmpty(): bool
    {
        return ($this->isInformational() && $this !== self::SWITCHING_PROTOCOLS)
            || $this === self::NO_CONTENT
            || $this === self::RESET_CONTENT
            || $this === self::NOT_MODIFIED;
    }

    public function allowsBody(): bool
    {
        return !$this->isEmpty();
    }

    /* ── RFC-default cacheability (RFC 9111 §4.2.2) ───────────────────── */
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

    public function needsLocationHeader(): bool
    {
        return $this === self::CREATED || $this->isRedirect();
    }

    /* ── BC shims ─────────────────────────────────────────────────────── */
    public static function text(int $code): string
    {
        return self::tryFrom($code)?->reason() ?? '';
    }

    public static function isEmptyCode(int $code): bool
    {
        return self::tryFrom($code)?->isEmpty()
            ?? ($code >= 100 && $code < 200);
    }
}
