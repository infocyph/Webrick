<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware\Maintenance;

use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Response\Response;

/** Shared maintenance response metadata and fallback-message policy. */
final readonly class MaintenanceResponsePolicy
{
    public const string DEFAULT_MESSAGE = 'Service is down for maintenance.';

    public function __construct(
        private int $retryAfter = 3600,
        private string $contentType = MediaTypeEnum::PLAIN->value,
    ) {
        if ($this->retryAfter < 0) {
            throw new \InvalidArgumentException('Maintenance Retry-After must be >= 0.');
        }
        if (trim($this->contentType) === '') {
            throw new \InvalidArgumentException('Maintenance content type must be non-empty.');
        }
    }

    public function exception(string $message): HttpException
    {
        return HttpException::serviceUnavailable(
            self::normalizeMessage($message),
            $this->exceptionHeaders(),
        );
    }

    public function response(string $message): Response
    {
        $status = StatusEnum::SERVICE_UNAVAILABLE;

        return Response::plaintext(
            $status->value . ' ' . $status->reason() . "\n" . self::normalizeMessage($message),
            $status->value,
            $this->responseHeaders(),
        );
    }

    private static function normalizeMessage(string $message): string
    {
        return trim($message) === '' ? self::DEFAULT_MESSAGE : $message;
    }

    /** @return array<string,string> */
    private function exceptionHeaders(): array
    {
        return [
            'Retry-After' => (string) $this->retryAfter,
            'Content-Type' => $this->contentType,
        ];
    }

    /** @return array<string,string> */
    private function responseHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Accept',
            ...$this->exceptionHeaders(),
        ];
    }
}
