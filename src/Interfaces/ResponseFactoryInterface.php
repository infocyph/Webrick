<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Interfaces;

use Psr\Http\Message\ResponseFactoryInterface as PsrResponseFactory;
use Psr\Http\Message\StreamFactoryInterface as PsrStreamFactory;

/**
 * Convenience umbrella: build *both* responses and streams
 * without juggling two separate factories.
 *
 * Implementations may simply delegate to any PSR-17 factories.
 */
interface ResponseFactoryInterface extends PsrResponseFactory, PsrStreamFactory
{
    // no extra members – the merge provides:
    // • createResponse(int $code = 200, string $reasonPhrase = '')
    // • createStream(string $contents = '')
    // • createStreamFromFile(string $filename, string $mode = 'r')
    // • createStreamFromResource(mixed $resource)  ← still allowed by PSR; implementations will narrow.
}
