<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Generator;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Response\Response;
use RuntimeException;
use UnexpectedValueException;

final readonly class ResponseWriterSupport
{
    private const array HTTP2_FORBIDDEN = [
        'connection' => true,
        'keep-alive' => true,
        'proxy-connection' => true,
        'transfer-encoding' => true,
        'upgrade' => true,
    ];

    public static function allowsBody(Response $response, RuntimeRequestContext $context): bool
    {
        return $context->routing->method !== HttpMethodEnum::HEAD->value
            && !self::statusHasNoContent($response->getStatusCode());
    }

    /**
     * @phpstan-return Generator<int,string,void,void>
     */
    public static function chunks(Response $response, int $chunkSize = 65_536): iterable
    {
        if ($chunkSize <= 0) {
            throw new \InvalidArgumentException('Response chunk size must be greater than zero.');
        }

        $producer = $response->getProducer();
        if ($producer !== null) {
            yield from self::producerChunks($producer);

            return;
        }

        $string = $response->getStringBody();
        if ($string !== null) {
            if ($string !== '') {
                yield $string;
            }

            return;
        }

        yield from self::bodyChunks($response->getBody(), $chunkSize);
    }

    public static function headerAllowed(string $name, string $value, bool $http2): bool
    {
        if (!$http2) {
            return true;
        }

        $lower = strtolower($name);
        if (isset(self::HTTP2_FORBIDDEN[$lower])) {
            return false;
        }

        return $lower !== 'te' || strtolower(trim($value)) === 'trailers';
    }

    /** @return array<string,list<string>> */
    public static function headerMap(Response $response, bool $http2 = false): array
    {
        $headers = [];
        foreach (self::headers($response, $http2) as [$name, $value]) {
            $headers[$name][] = $value;
        }

        return $headers;
    }

    /** @return Generator<int,array{0:string,1:string},void,void> */
    public static function headers(Response $response, bool $http2 = false): Generator
    {
        $status = $response->getStatusCode();
        foreach ($response->getHeaders() as $name => $values) {
            if (strtolower($name) === 'content-length' && self::forbidsContentLength($status)) {
                continue;
            }
            foreach ($values as $value) {
                if (self::headerAllowed($name, $value, $http2)) {
                    yield [$name, $value];
                }
            }
        }
    }

    public static function knownLength(Response $response): ?int
    {
        if ($response->isStreaming() || self::statusHasNoContent($response->getStatusCode())) {
            return null;
        }

        return $response->getBodySize();
    }

    /** @phpstan-impure */
    private static function atEnd(\Infocyph\Webrick\Interfaces\BodyStream $body): bool
    {
        return $body->eof();
    }

    /** @return Generator<int,string,void,void> */
    private static function bodyChunks(\Infocyph\Webrick\Interfaces\BodyStream $body, int $chunkSize): Generator
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!self::atEnd($body)) {
            $chunk = $body->read($chunkSize);
            if ($chunk !== '') {
                yield $chunk;

                continue;
            }
            if (self::atEnd($body)) {
                return;
            }

            throw new RuntimeException('Response body stream made no read progress before EOF.');
        }
    }

    private static function forbidsContentLength(int $status): bool
    {
        return ($status >= 100 && $status < 200)
            || $status === StatusEnum::NO_CONTENT->value;
    }

    /** @return Generator<int,string,void,void> */
    private static function producerChunks(\Closure $producer): Generator
    {
        $chunks = $producer();
        if (!is_iterable($chunks)) {
            throw new UnexpectedValueException('Streaming response producer must return an iterable.');
        }
        foreach ($chunks as $chunk) {
            if (!is_string($chunk)) {
                throw new UnexpectedValueException('Streaming response producers must yield strings.');
            }
            yield $chunk;
        }
    }

    private static function statusHasNoContent(int $status): bool
    {
        return ($status >= 100 && $status < 200)
            || $status === StatusEnum::NO_CONTENT->value
            || $status === StatusEnum::RESET_CONTENT->value
            || $status === StatusEnum::NOT_MODIFIED->value;
    }
}
