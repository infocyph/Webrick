<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Payloads;

use Infocyph\Webrick\Constants\MediaTypeEnum;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;

final class JsonResponse extends Response
{
    /**
     * Create a Response containing JSON-encoded data.
     *
     * Behaviour:
     *  - Encodes $data using json_encode() with the provided $flags and $depth.
     *  - Throws a RuntimeException if encoding fails.
     *  - Ensures a Content-Type header is present (defaults to application/json).
     *
     * @param mixed $data Value to be JSON-encoded for the response body.
     * @param int $status HTTP status code (default: 200).
     * @param array<string,string> $headers Additional response headers (name => value).
     * @param int $flags json_encode flags (default: JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE).
     * @param int $depth Maximum depth for json_encode (default: 512).
     * @throws \RuntimeException When json_encode() fails.
     */
    public function __construct(
        mixed $data,
        int $status = 200,
        array $headers = [],
        int $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        int $depth = 512,
    ) {
        $json = json_encode($data, $flags, $depth);
        if ($json === false) {
            throw new \RuntimeException('JSON encode error: ' . json_last_error_msg());
        }

        $headers += ['Content-Type' => MediaTypeEnum::fromExtension('json')];
        parent::__construct($status, new Stream($json), $headers);
    }
}
