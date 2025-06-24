<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Tiny (PSR-17–compatible) factory abstraction so
 * middleware and unit tests can create responses/streams
 * without coupling to concrete classes.
 */
interface ResponseFactoryInterface
{
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface;

    public function createStream(string $content = ''): StreamInterface;
}
