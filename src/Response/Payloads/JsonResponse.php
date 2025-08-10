<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Payloads;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Constants\Mime;

final class JsonResponse extends Response
{
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

        $headers += ['Content-Type' => Mime::fromExtension('json')];
        parent::__construct($status, new Stream($json), $headers);
    }
}
