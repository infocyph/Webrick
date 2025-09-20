<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Payloads;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;

/**
 * Response representing an HTTP redirect.
 *
 * Convenience subclass that sets a Location header and an empty body.
 * Ensures the status code is a valid 3xx redirect code.
 */
final class RedirectResponse extends Response
{
    /**
     * Create a redirect response.
     *
     * Behaviour:
     *  - Validates that $status is a 3xx redirect status (300-399).
     *  - Ensures a 'Location' header is present (will be added if absent).
     *  - Uses an empty body stream and delegates to the parent Response constructor.
     *
     * @param string $uri Absolute or relative URI to redirect to.
     * @param int $status HTTP redirect status (301,302,303,307,308). Default 302.
     * @param array<string,string> $headers Optional additional headers (name => value).
     * @throws \InvalidArgumentException If $status is not a 3xx code.
     */
    public function __construct(
        string $uri,
        int $status = 302,
        array $headers = [],
    ) {
        if ($status < 300 || $status > 399) {
            throw new \InvalidArgumentException("Redirect status must be 3xx; {$status} given.");
        }
        $headers += ['Location' => $uri];
        parent::__construct($status, new Stream(''), $headers);
    }
}
