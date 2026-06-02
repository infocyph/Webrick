<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Response\Response;

/**
 * Configurable synchronous emitter used for classic SAPIs.
 */
final class DefaultEmitter extends BaseEmitter
{
    public const string FINISH_FASTCGI = 'fastcgi';

    public const string FINISH_FRANKENPHP = 'frankenphp';

    public const string FINISH_LITESPEED = 'litespeed';

    public const string FINISH_NONE = 'none';

    public function __construct(
        private readonly string $finishMode = self::FINISH_NONE,
        private readonly bool $chunked = false,
    ) {}

    #[\Override]
    protected function finish(): void
    {
        match ($this->finishMode) {
            self::FINISH_FASTCGI => \function_exists('fastcgi_finish_request') ? \fastcgi_finish_request() : null,
            self::FINISH_FRANKENPHP => \function_exists('frankenphp_finish_request') ? \frankenphp_finish_request() : null,
            self::FINISH_LITESPEED => \function_exists('litespeed_finish_request') ? \litespeed_finish_request() : null,
            default => null,
        };
    }

    #[\Override]
    protected function wantsChunked(
        bool $isHttp11,
        bool $allowsBody,
        Response $response,
        bool $isStreaming,
        ?int $size,
    ): bool {
        return $this->chunked
            && $isHttp11
            && $allowsBody
            && !$response->hasHeader('Content-Length')
            && !$response->hasHeader('Transfer-Encoding')
            && ($isStreaming || $size === null);
    }
}
