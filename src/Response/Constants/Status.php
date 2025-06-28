<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Constants;

/**
 * Registry of every official HTTP status we care about.
 *  • Static helpers keep Response::withStatus() 100 % branch-free.
 *  • Text map is stored in a single static array – opcode cached.
 */
final class Status
{
    /** @var array<int,string> */
    private const TEXT = [
        /* ========== 1xx ========== */
        100 => 'Continue',                       101 => 'Switching Protocols',
        102 => 'Processing',                     103 => 'Early Hints',

        /* ========== 2xx ========== */
        200 => 'OK',                             201 => 'Created',
        202 => 'Accepted',                       203 => 'Non-Authoritative Information',
        204 => 'No Content',                     205 => 'Reset Content',
        206 => 'Partial Content',                207 => 'Multi-Status',
        208 => 'Already Reported',               226 => 'IM Used',

        /* ========== 3xx ========== */
        300 => 'Multiple Choices',               301 => 'Moved Permanently',
        302 => 'Found',                          303 => 'See Other',
        304 => 'Not Modified',                   305 => 'Use Proxy',
        307 => 'Temporary Redirect',             308 => 'Permanent Redirect',

        /* ========== 4xx ========== */
        400 => 'Bad Request',                    401 => 'Unauthorized',
        402 => 'Payment Required',               403 => 'Forbidden',
        404 => 'Not Found',                      405 => 'Method Not Allowed',
        406 => 'Not Acceptable',                 407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',                409 => 'Conflict',
        410 => 'Gone',                           411 => 'Length Required',
        412 => 'Precondition Failed',            413 => 'Payload Too Large',
        414 => 'URI Too Long',                   415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',          417 => 'Expectation Failed',
        418 => 'I’m a teapot',                   421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',           423 => 'Locked',
        424 => 'Failed Dependency',              425 => 'Too Early',
        426 => 'Upgrade Required',               428 => 'Precondition Required',
        429 => 'Too Many Requests',              431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',

        /* ========== 5xx ========== */
        500 => 'Internal Server Error',          501 => 'Not Implemented',
        502 => 'Bad Gateway',                    503 => 'Service Unavailable',
        504 => 'Gateway Timeout',                505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',        507 => 'Insufficient Storage',
        508 => 'Loop Detected',                  510 => 'Not Extended',
        511 => 'Network Authentication Required',

        /* ===== custom (project) ===== */
        509 => 'Bandwidth Limit Exceeded',
    ];

    /** True if this status MUST NOT include a body (RFC-semantics). */
    public static function isEmpty(int $code): bool
    {
        return match ($code) {
            204, 205, 304                    => true,
            default                          => $code >= 100 && $code < 200
        };
    }

    /** RFC-conforming reason phrase (never empty; falls back to ‘’). */
    public static function text(int $code): string
    {
        return self::TEXT[$code] ?? '';
    }
}
