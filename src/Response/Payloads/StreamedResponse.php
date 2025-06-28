<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Payloads;

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Stream;
use Psr\Http\Message\StreamInterface;

/**
 * Wrap a PHP callable / Generator as a PSR-7 stream.
 *
 * The emitter should check `$response->getBody()->eof()` and chunk-flush.
 */
final class StreamedResponse extends Response
{
    /**
     * @param callable():string|callable():\Generator|StreamInterface $source
     *        • string-returning closure (chunks) or generator yielding strings
     *        • OR an existing StreamInterface.
     * @param int                                                     $status
     * @param array                                                   $headers
     */
    public function __construct(
        callable|StreamInterface $source,
        int                      $status  = 200,
        array                    $headers = [],
    ) {
        $body = $source instanceof StreamInterface
            ? $source
            : new Stream(self::wrap($source));

        parent::__construct($status, $body, $headers);
    }

    /** Convert callable / generator into PHP stream resource for Stream wrapper. */
    private static function wrap(callable $fn): string|resource
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
