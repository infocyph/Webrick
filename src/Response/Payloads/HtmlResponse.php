<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Payloads;

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Stream;
use Infocyph\Webrick\Response\Constants\Mime;

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
