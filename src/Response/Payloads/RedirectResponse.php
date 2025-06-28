<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Payloads;

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Response\Stream;

final class RedirectResponse extends Response
{
    /**
     * @param string $uri        Absolute or relative URI.
     * @param int    $status     301, 302, 303, 307, 308 (default 302).
     * @param array  $headers    Extra headers.
     */
    public function __construct(
        string $uri,
        int    $status  = 302,
        array  $headers = [],
    ) {
        if ($status < 300 || $status > 399) {
            throw new \InvalidArgumentException("Redirect status must be 3xx; {$status} given.");
        }
        $headers += ['Location' => $uri];
        parent::__construct($status, new Stream(''), $headers);
    }
}
