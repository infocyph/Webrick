<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use Generator;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Response\Response;

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
            && !StatusEnum::isEmptyCode($response->getStatusCode());
    }

    /**
     * @return iterable<string>
     */
    public static function chunks(Response $response, int $chunkSize = 65_536): iterable
    {
        $producer = $response->getProducer();
        if ($producer !== null) {
            yield from self::nonEmptyChunks($producer());

            return;
        }

        $string = $response->getStringBody();
        if ($string !== null) {
            if ($string !== '') {
                yield $string;
            }

            return;
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            $chunk = $body->read($chunkSize);
            if ($chunk === '') {
                break;
            }
            yield $chunk;
        }
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

    /**
     * @return Generator<array{0:string,1:string}>
     */
    public static function headers(Response $response, bool $http2 = false): Generator
    {
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                if (self::headerAllowed($name, $value, $http2)) {
                    yield [$name, $value];
                }
            }
        }
    }

    public static function knownLength(Response $response): ?int
    {
        if ($response->isStreaming() || StatusEnum::isEmptyCode($response->getStatusCode())) {
            return null;
        }

        return $response->getBodySize();
    }

    /**
     * @param iterable<string> $chunks
     * @return iterable<string>
     */
    private static function nonEmptyChunks(iterable $chunks): iterable
    {
        foreach ($chunks as $chunk) {
            if ($chunk !== '') {
                yield $chunk;
            }
        }
    }
}
