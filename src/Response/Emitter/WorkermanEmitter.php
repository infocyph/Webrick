<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Internal\Utils;
use Infocyph\Webrick\Response\Response;

final class WorkermanEmitter implements EmitterInterface
{
    /** @var array<class-string,\ReflectionMethod> */
    private static array $endMethods = [];

    public function emit(Response $response, ?Request $request = null): void
    {
        if (!$request instanceof Request) {
            throw new \RuntimeException('WorkermanEmitter requires a Request instance.');
        }

        $native = $request->getAttribute('workerman.response');
        if (is_object($native) && method_exists($native, 'withStatus') && $this->emitViaNativeResponse($native, $response, $request)) {
            return;
        }

        $connection = $request->getAttribute('workerman.connection');
        if (is_object($connection) && method_exists($connection, 'send') && $this->emitViaConnection($connection, $response, $request)) {
            return;
        }

        throw new \RuntimeException('WorkermanEmitter requires "workerman.response" or "workerman.connection" attribute.');
    }

    private function bodyString(Response $response): string
    {
        return $response->getStringBody() ?? (string) $response->getBody();
    }

    private function emitViaConnection(object $connection, Response $response, Request $request): bool
    {
        if (!is_callable([$connection, 'send'])) {
            return false;
        }

        $body = $this->shouldEmitEmptyBody($response, $request) ? '' : $this->bodyString($response);
        $headers = $this->withContentLength($this->normalizeHeaders($response->getHeaders()), $body);
        $status = $response->getStatusCode() . ' ' . $response->getReasonPhrase();
        $buffer = "HTTP/{$response->getProtocolVersion()} {$status}\r\n";
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $buffer .= "{$name}: {$value}\r\n";
            }
        }
        $buffer .= "\r\n" . $body;
        $connection->send($buffer);

        return true;
    }

    private function emitViaNativeResponse(object $native, Response $response, Request $request): bool
    {
        if (!is_callable([$native, 'withStatus']) || !is_callable([$native, 'end'])) {
            return false;
        }

        $next = $native->withStatus($response->getStatusCode());
        if (!is_object($next)) {
            return false;
        }
        $native = $next;
        foreach ($this->normalizeHeaders($response->getHeaders()) as $name => $values) {
            foreach ($values as $value) {
                if (!is_callable([$native, 'withHeader'])) {
                    return false;
                }
                $next = $native->withHeader($name, $value);
                if (!is_object($next)) {
                    return false;
                }
                $native = $next;
            }
        }

        $body = $this->shouldEmitEmptyBody($response, $request) ? '' : $this->bodyString($response);
        $class = $native::class;
        (self::$endMethods[$class] ??= new \ReflectionMethod($native, 'end'))->invoke($native, $body);

        return true;
    }

    /** @param array<mixed> $headers @return array<string,array<int,string>> */
    private function normalizeHeaders(array $headers): array
    {
        return Utils::normalizeHeaderValueLists($headers);
    }

    private function shouldEmitEmptyBody(Response $response, Request $request): bool
    {
        if (HttpMethodEnum::normalize($request->getMethod()) === HttpMethodEnum::HEAD->value) {
            return true;
        }

        return in_array(
            $response->getStatusCode(),
            [StatusEnum::NO_CONTENT->value, StatusEnum::NOT_MODIFIED->value],
            true,
        );
    }

    /**
     * @param array<string,array<int,string>> $headers
     * @return array<string,array<int,string>>
     */
    private function withContentLength(array $headers, string $body): array
    {
        $hasLength = array_any(
            $headers,
            static fn(array $values, string|int $name): bool => $values !== [] && strtolower((string) $name) === 'content-length',
        );
        if (!$hasLength) {
            $headers['Content-Length'] = [(string) strlen($body)];
        }

        return $headers;
    }
}
