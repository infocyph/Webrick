<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Payloads;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;

final class HtmlResponse extends Response
{
    public function __construct(
        string $html,
        int    $status  = 200,
        array  $headers = [],
        string $charset = 'utf-8',
    ) {
        $headers += ['Content-Type' => "text/html; charset={$charset}"];
        parent::__construct($status, new Stream($html), $headers);
    }
}
