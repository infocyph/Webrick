<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Payloads;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;

/**
 * Wrap a PHP callable / Generator as a PSR-7 stream.
 *
 * The emitter should check `$response->getBody()->eof()` and chunk-flush.
 */
final class StreamedResponse extends Response
{
    /**
     * @param callable():string|callable():\Generator|Stream $source
     *        • string-returning closure (chunks) or generator yielding strings
     *        • OR an existing Stream.
     * @param int $status
     * @param array $headers
     */
    public function __construct(
        callable|Stream $source,
        int $status = 200,
        array $headers = [],
    ) {
        $body = $source instanceof Stream
            ? $source
            : new Stream(self::wrap($source));

        parent::__construct($status, $body, $headers);
    }

    /** Convert callable / generator into PHP stream resource for Stream wrapper. */
    private static function wrap(callable $fn): mixed
    {
        $tmp = fopen('php://temp', 'r+');

        // Use generator style for efficient memory usage
        $iter = $fn();
        if ($iter instanceof \Generator) {
            foreach ($iter as $chunk) {
                fwrite($tmp, $chunk);
            }
        } else {
            fwrite($tmp, $iter);
        }
        rewind($tmp);
        return $tmp;
    }
}
