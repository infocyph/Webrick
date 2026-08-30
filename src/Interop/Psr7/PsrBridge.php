<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interop\Psr7;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Optional PSR-7/PSR-17 interoperability boundary.
 *
 * Webrick remains native internally. This class is only load/use-worthy when a
 * consuming application installs psr/http-message plus psr/http-factory.
 */
final class PsrBridge
{
    public function __construct(private readonly StreamFactoryInterface $streams) {}

    public function response(Response $source, ResponseFactoryInterface $factory): ResponseInterface
    {
        $target = $factory->createResponse($source->getStatusCode(), $source->getReasonPhrase())
            ->withProtocolVersion($source->getProtocolVersion());

        foreach ($source->getHeaders() as $name => $values) {
            $target = $target->withHeader($name, $values);
        }

        $body = $source->getStringBody();
        if ($body === null) {
            $stream = $source->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = $stream->getContents();
        }

        return $target->withBody($this->streams->createStream($body));
    }

    public function serverRequest(Request $source, ServerRequestFactoryInterface $factory): ServerRequestInterface
    {
        $target = $factory->createServerRequest(
            $source->getMethod(),
            (string) $source->getUri(),
            $source->getServerParams(),
        )->withProtocolVersion($source->getProtocolVersion())
            ->withQueryParams($source->getQueryParams())
            ->withCookieParams($source->getCookieParams())
            ->withParsedBody($source->getParsedBody());

        foreach ($source->getHeaders() as $name => $values) {
            $target = $target->withHeader($name, $values);
        }
        foreach ($source->getAttributes() as $name => $value) {
            $target = $target->withAttribute($name, $value);
        }

        $body = $source->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $target->withBody($this->streams->createStream($body->getContents()));
    }
}
