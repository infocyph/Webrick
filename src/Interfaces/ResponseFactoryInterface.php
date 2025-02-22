<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Minimal subset of PSR-17 for creating responses & streams
 */
interface ResponseFactoryInterface
{
    /**
     * Create a new Response with the given status code and reason phrase.
     */
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface;

    /**
     * Create a new Stream with given content.
     */
    public function createStream(string $content = ''): StreamInterface;
}
