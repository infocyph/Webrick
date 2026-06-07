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
 * @method static self badRequest(string $message, array<string,string> $headers = [])
 * @method static self forbidden(string $message, array<string,string> $headers = [])
 * @method static self gone(string $message, array<string,string> $headers = [])
 * @method static self notAcceptable(string $message, array<string,string> $headers = [])
 * @method static self payloadTooLarge(string $message, array<string,string> $headers = [])
 * @method static self requestHeaderFieldsTooLarge(string $message, array<string,string> $headers = [])
 * @method static self serviceUnavailable(string $message, array<string,string> $headers = [])
 * @method static self tooManyRequests(string $message, array<string,string> $headers = [])
 */
class HttpException extends \RuntimeException implements HttpExceptionInterface
{
    /**
     * @var array<string, StatusEnum>
     */
    private const array STATUS_METHODS = [
        'badRequest' => StatusEnum::BAD_REQUEST,
        'forbidden' => StatusEnum::FORBIDDEN,
        'gone' => StatusEnum::GONE,
        'notAcceptable' => StatusEnum::NOT_ACCEPTABLE,
        'payloadTooLarge' => StatusEnum::PAYLOAD_TOO_LARGE,
        'requestHeaderFieldsTooLarge' => StatusEnum::REQUEST_HEADER_FIELDS_TOO_LARGE,
        'serviceUnavailable' => StatusEnum::SERVICE_UNAVAILABLE,
        'tooManyRequests' => StatusEnum::TOO_MANY_REQUESTS,
    ];

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

    /** @param list<mixed> $args */
    public static function __callStatic(string $method, array $args): self
    {
        $status = self::STATUS_METHODS[$method] ?? null;
        if (!$status instanceof StatusEnum) {
            throw new \BadMethodCallException("Undefined HTTP exception factory {$method}().");
        }

        $message = $args[0] ?? null;
        $headers = $args[1] ?? [];
        if (!\is_string($message) || !\is_array($headers)) {
            throw new InvalidArgumentException("Invalid arguments for HTTP exception factory {$method}().");
        }

        /** @var HeaderMap $headers */
        return self::fromStatus($status, $message, $headers);
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
