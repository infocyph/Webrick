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
        if ($context->routing->method === HttpMethodEnum::HEAD->value) {
            return false;
        }

        return !in_array(
            $response->getStatusCode(),
            [StatusEnum::NO_CONTENT->value, StatusEnum::NOT_MODIFIED->value],
            true,
        );
    }

    /** @return Generator<array{0:string,1:string}> */
    public static function headers(Response $response, bool $http2 = false): Generator
    {
        foreach ($response->getHeaders() as $name => $values) {
            $lower = strtolower($name);
            foreach ($values as $value) {
                if ($http2 && isset(self::HTTP2_FORBIDDEN[$lower])) {
                    continue;
                }
                if ($http2 && $lower === 'te' && strtolower(trim($value)) !== 'trailers') {
                    continue;
                }

                yield [$name, $value];
            }
        }
    }

    public static function knownLength(Response $response): ?int
    {
        return $response->isStreaming() ? null : $response->getBodySize();
    }

    /** @return iterable<string> */
    public static function chunks(Response $response, int $chunkSize = 65_536): iterable
    {
        $producer = $response->getProducer();
        if ($producer !== null) {
            $output = $producer();
            if (is_iterable($output)) {
                foreach ($output as $chunk) {
                    if (is_string($chunk) && $chunk !== '') {
                        yield $chunk;
                    } elseif (is_scalar($chunk)) {
                        $value = (string) $chunk;
                        if ($value !== '') {
                            yield $value;
                        }
                    }
                }

                return;
            }

            if (is_string($output) && $output !== '') {
                yield $output;
            }

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
}
