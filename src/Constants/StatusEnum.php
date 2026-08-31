<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Constants;

/** HTTP status codes as int-backed enum with convenience helpers. */
enum StatusEnum: int
{
    case ACCEPTED = 202;

    case ALREADY_REPORTED = 208;

    case BAD_GATEWAY = 502;

    case BAD_REQUEST = 400;

    case BANDWIDTH_LIMIT_EXCEEDED = 509;

    case CONFLICT = 409;

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

    case INTERNAL_SERVER_ERROR = 500;

    case LENGTH_REQUIRED = 411;

    case LOCKED = 423;

    case LOOP_DETECTED = 508;

    case METHOD_NOT_ALLOWED = 405;

    case MISDIRECTED_REQUEST = 421;

    case MOVED_PERMANENTLY = 301;

    case MULTI_STATUS = 207;

    case MULTIPLE_CHOICES = 300;

    case NETWORK_AUTH_REQUIRED = 511;

    case NO_CONTENT = 204;

    case NON_AUTHORITATIVE_INFO = 203;

    case NOT_ACCEPTABLE = 406;

    case NOT_EXTENDED = 510;

    case NOT_FOUND = 404;

    case NOT_IMPLEMENTED = 501;

    case NOT_MODIFIED = 304;

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

    public static function isEmptyCode(int $code): bool
    {
        return ($code >= 100 && $code < 200)
            || $code === self::NO_CONTENT->value
            || $code === self::RESET_CONTENT->value
            || $code === self::NOT_MODIFIED->value;
    }

    public static function isErrorCode(int $code): bool
    {
        return $code >= self::BAD_REQUEST->value && $code < 600;
    }

    public static function isServerErrorCode(int $code): bool
    {
        return $code >= self::INTERNAL_SERVER_ERROR->value && $code < 600;
    }

    public static function text(int $code): string
    {
        return self::tryFrom($code)?->reason() ?? '';
    }

    public function allowsBody(): bool
    {
        return !$this->isEmpty();
    }

    public function isCacheable(): bool
    {
        return match ($this) {
            self::OK, self::NON_AUTHORITATIVE_INFO, self::NO_CONTENT,
            self::PARTIAL_CONTENT, self::MULTIPLE_CHOICES,
            self::MOVED_PERMANENTLY, self::GONE,
            self::UNAUTHORIZED, self::FORBIDDEN,
            self::FOUND, self::SEE_OTHER => true,
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

    public function isClientError(): bool
    {
        return $this->series() === 4;
    }

    public function isEmpty(): bool
    {
        return $this->isInformational()
            || $this === self::NO_CONTENT
            || $this === self::RESET_CONTENT
            || $this === self::NOT_MODIFIED;
    }

    public function isInformational(): bool
    {
        return $this->series() === 1;
    }

    public function isRedirect(): bool
    {
        return $this->series() === 3;
    }

    public function isServerError(): bool
    {
        return $this->series() === 5;
    }

    public function isSuccess(): bool
    {
        return $this->series() === 2;
    }

    public function needsLocationHeader(): bool
    {
        return $this === self::CREATED || $this->isRedirect();
    }

    public function reason(): string
    {
        static $irregular = [
            self::MULTI_STATUS->value => 'Multi-Status',
            self::NON_AUTHORITATIVE_INFO->value => 'Non-Authoritative Information',
            self::IM_A_TEAPOT->value => "I'm a teapot",
            self::NETWORK_AUTH_REQUIRED->value => 'Network Authentication Required',
        ];
        static $cache = [];

        $code = $this->value;

        return $irregular[$code] ?? $cache[$code] ??= ucwords(strtolower(str_replace('_', ' ', $this->name)));
    }

    public function series(): int
    {
        return intdiv($this->value, 100);
    }
}
