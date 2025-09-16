<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Constants;

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

    /**
     * Returns the human-readable reason phrase associated with the given HTTP status code.
     *
     * If the status code is not recognized, an empty string is returned.
     *
     * @return string The human-readable reason phrase associated with the status code.
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
     * Returns the HTTP status code series (1xx, 2xx, 3xx, etc.) as an integer.
     *
     * The series is calculated by dividing the status code by 100.
     *
     * @return int The HTTP status code series.
     */
    public function series(): int
    {
        return intdiv($this->value, 100);
    }

    /**
     * Checks if the HTTP status code is an informational response (100-199).
     *
     * Informational responses are used for providing information about the connection
     * status or for indicating pending redirects. The client should not repeat the request
     * without user confirmation.
     *
     * @return bool True if the status code is an informational response, false otherwise.
     */
    public function isInformational(): bool
    {
        return $this->series() === 1;
    }

    /**
     * Checks if the HTTP status code is a success (200-299).
     *
     * Success status codes indicate that the request was successfully received,
     * understood, and accepted.
     *
     * @return bool True if the status code is a success, false otherwise.
     */
    public function isSuccess(): bool
    {
        return $this->series() === 2;
    }

    /**
     * Checks if the HTTP status code is a redirect (300-399).
     *
     * Redirect status codes indicate that the client must take additional action
     * to fulfill the request. The client may repeat the request with a new
     * location or without performing the redirect.
     *
     * @return bool True if the status code is a redirect, false otherwise.
     */
    public function isRedirect(): bool
    {
        return $this->series() === 3;
    }

    /**
     * Checks if the HTTP status code is a client error (400-499).
     *
     * Client error status codes indicate that the request contains bad syntax or
     * cannot be fulfilled.
     *
     * @return bool True if the status code is a client error, false otherwise.
     */
    public function isClientError(): bool
    {
        return $this->series() === 4;
    }

    /**
     * Checks if the HTTP status code is a server error (500-599).
     *
     * Server error status codes indicate that the server is aware that it has
     * encountered an error or is incapable of performing the request.
     *
     * @return bool True if the status code is a server error, false otherwise.
     */
    public function isServerError(): bool
    {
        return $this->series() === 5;
    }

    /**
     * Checks if the HTTP status code is empty (100-199).
     *
     * If the status code is not recognized, it will check if the code is
     * within the 1xx range (Informational responses).
     *
     * Otherwise, the status code is not cacheable by default.
     *
     * @return bool True if the status code is empty, false otherwise.
     */
    public function isEmpty(): bool
    {
        return ($this->isInformational() && $this !== self::SWITCHING_PROTOCOLS)
            || $this === self::NO_CONTENT
            || $this === self::RESET_CONTENT
            || $this === self::NOT_MODIFIED;
    }

    /**
     * Returns true if the response body is allowed, false otherwise.
     *
     * The response body is allowed unless the status code is informational
     * (1xx), or one of the following:
     * - 204 No Content
     * - 304 Not Modified
     *
     * @return bool True if the response body is allowed, false otherwise.
     */
    public function allowsBody(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Checks if the HTTP response is cacheable by default.
     *
     * By default, the following status codes are considered cacheable:
     * - 200 OK
     * - 203 Non-Authoritative Information
     * - 204 No Content
     * - 206 Partial Content
     * - 300 Multiple Choices
     * - 301 Moved Permanently
     * - 302 Found
     * - 303 See Other
     * - 403 Forbidden
     * - 404 Not Found
     * - 410 Gone
     * - 401 Unauthorized
     * - If explicit Expires/Cache-Control headers are present
     * - If heuristics are allowed
     *
     * @return bool True if the response is cacheable by default, false otherwise.
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
     * Checks if the HTTP response is cacheable by default.
     *
     * If the response code is one of the following, the response is considered cacheable by default:
     * - 200 OK
     * - 203 Non-Authoritative Information
     * - 206 Partial Content
     * - 301 Moved Permanently
     * - 410 Gone
     *
     * Otherwise, the response is not cacheable by default.
     *
     * @return bool True if the response is cacheable by default, false otherwise.
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
     * Indicates whether the HTTP response should include a Location header.
     *
     * The Location header is included for 201 Created responses, as well as for 3xx Redirect responses.
     *
     * @return bool True if the response should include a Location header, false otherwise.
     */
    public function needsLocationHeader(): bool
    {
        return $this === self::CREATED || $this->isRedirect();
    }

    /**
     * Returns the human-readable reason phrase associated with the given HTTP status code.
     *
     * If the status code is not recognized, an empty string is returned.
     *
     * @param int $code The HTTP status code.
     * @return string The human-readable reason phrase associated with the status code.
     */
    public static function text(int $code): string
    {
        return self::tryFrom($code)?->reason() ?? '';
    }

    /**
     * Checks if the given HTTP status code is empty (100-199).
     *
     * If the status code is not recognized, it will check if the code is
     * within the 1xx range (Informational responses).
     *
     * @param int $code The HTTP status code to check.
     * @return bool True if the status code is empty, false otherwise.
     */
    public static function isEmptyCode(int $code): bool
    {
        return self::tryFrom($code)?->isEmpty()
            ?? ($code >= 100 && $code < 200);
    }
}
