<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Contracts;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Narrow PSR-17 subset used internally. A mirror of the earlier interface,
 * now under a stable namespace so userland code can depend on it.
 */
interface ResponseFactoryInterface
{
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface;
    public function createStream(string $content = ''): StreamInterface;
}
