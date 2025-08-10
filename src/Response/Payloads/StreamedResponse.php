<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Payloads;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;

/**
 * Wrap a PHP callable / Generator as a PSR-7 stream.
 *
 * Default behaviour remains **buffered** (the producer is written to php://temp
 * and exposed as a Stream body). For true on-the-fly streaming, an emitter can
 * call getProducer() and stream directly from the callable/generator.
 */
final class StreamedResponse extends Response
{
    /** @var null|callable():string|\Generator */
    private $producer = null;

    /**
     * @param callable():string|callable():\Generator|Stream $source
     *        • string-returning closure (chunks) or generator yielding strings
     *        • OR an existing Stream.
     */
    public function __construct(
        callable|Stream $source,
        int $status = 200,
        array $headers = [],
    ) {
        if ($source instanceof Stream) {
            $body = $source;
        } else {
            $this->producer = $source;                  // expose for true streaming
            $body = new Stream(self::wrap($source));    // keep buffered body for PSR-7 consumers
        }

        parent::__construct($status, $body, $headers);
    }

    /** If non-null, an emitter may stream directly from this producer. */
    public function getProducer(): ?callable
    {
        return $this->producer;
    }

    /** Convert callable / generator into PHP stream resource for Stream wrapper. */
    private static function wrap(callable $fn): mixed
    {
        $tmp = fopen('php://temp', 'r+');

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
