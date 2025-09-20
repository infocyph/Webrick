<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Payloads;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Response\Response;

/**
 * Convenience Response pre-filled for HTML payloads.
 *
 * Creates a Response with a string body and a sensible default
 * Content-Type header of "text/html; charset=<charset>" when not provided.
 */
final class HtmlResponse extends Response
{
    /**
     * Create a new HTML response.
     *
     * The constructor accepts an HTML string body and optional status, headers and charset.
     * If the Content-Type header is not present in $headers it will be set to
     * "text/html; charset={$charset}" before delegating to the parent Response constructor.
     *
     * @param string $html HTML document or fragment to use as the response body.
     * @param int $status HTTP status code (default: 200).
     * @param array<string,string> $headers Additional response headers (name => value).
     * @param string $charset Character set to use in the Content-Type header (default: "utf-8").
     */
    public function __construct(
        string $html,
        int $status = 200,
        array $headers = [],
        string $charset = 'utf-8',
    ) {
        $headers += ['Content-Type' => "text/html; charset={$charset}"];
        parent::__construct($status, new Stream($html), $headers);
    }
}
