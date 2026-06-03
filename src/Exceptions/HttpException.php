<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Exceptions;

use Infocyph\Webrick\Constants\StatusEnum;
use InvalidArgumentException;
use Throwable;

/**
 * Generic HTTP exception for framework-owned short-circuit responses.
 *
 * @phpstan-type HeaderMap array<string,string>
 */
class HttpException extends \RuntimeException implements HttpExceptionInterface
{
    private readonly string $publicMessage;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        private readonly array $headers = [],
        ?string $publicMessage = null,
        ?Throwable $previous = null,
    ) {
        if (!StatusEnum::isErrorCode($statusCode)) {
            throw new InvalidArgumentException("HTTP exception status must be between 400 and 599, got {$statusCode}.");
        }

        $resolvedPublicMessage = $publicMessage ?? (StatusEnum::text($statusCode) ?: 'HTTP Error');
        parent::__construct($message !== '' ? $message : $resolvedPublicMessage, 0, $previous);

        $this->publicMessage = $resolvedPublicMessage;
    }

    /** @param HeaderMap $headers */
    public static function badRequest(string $message, array $headers = []): self
    {
        return self::fromStatus(StatusEnum::BAD_REQUEST, $message, $headers);
    }

    /** @param HeaderMap $headers */
    public static function forbidden(string $message, array $headers = []): self
    {
        return self::fromStatus(StatusEnum::FORBIDDEN, $message, $headers);
    }

    /** @param HeaderMap $headers */
    public static function gone(string $message, array $headers = []): self
    {
        return self::fromStatus(StatusEnum::GONE, $message, $headers);
    }

    /** @param HeaderMap $headers */
    public static function notAcceptable(string $message, array $headers = []): self
    {
        return self::fromStatus(StatusEnum::NOT_ACCEPTABLE, $message, $headers);
    }

    /** @param HeaderMap $headers */
    public static function payloadTooLarge(string $message, array $headers = []): self
    {
        return self::fromStatus(StatusEnum::PAYLOAD_TOO_LARGE, $message, $headers);
    }

    /** @param HeaderMap $headers */
    public static function requestHeaderFieldsTooLarge(string $message, array $headers = []): self
    {
        return self::fromStatus(StatusEnum::REQUEST_HEADER_FIELDS_TOO_LARGE, $message, $headers);
    }

    /** @param HeaderMap $headers */
    public static function serviceUnavailable(string $message, array $headers = []): self
    {
        return self::fromStatus(StatusEnum::SERVICE_UNAVAILABLE, $message, $headers);
    }

    /** @param HeaderMap $headers */
    public static function tooManyRequests(string $message, array $headers = []): self
    {
        return self::fromStatus(StatusEnum::TOO_MANY_REQUESTS, $message, $headers);
    }

    /**
     * @return array<string,string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getPublicMessage(): string
    {
        return $this->publicMessage;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @param array<string,string> $headers
     */
    private static function fromStatus(StatusEnum $status, string $message, array $headers = []): self
    {
        return new self($status->value, $message, $headers, $message);
    }
}
