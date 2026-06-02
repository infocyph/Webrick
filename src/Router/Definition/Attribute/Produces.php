<?php

// src/Router/Definition/Attribute/Produces.php
declare(strict_types=1);

namespace Infocyph\Webrick\Router\Definition\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
/**
 * Attribute that documents the response media types a route may produce.
 *
 * Apply to a controller method or class to declare the media types the route
 * can return. This information can be consumed by middleware, documentation
 * generators, or content-negotiation helpers.
 *
 * Examples:
 *   #[Produces(types: ['application/json'])]
 *   #[Produces(types: ['text/html'], charsets: ['utf-8'])]
 *
 * Properties:
 *  - public array $types      : list of media type strings (e.g. "application/json")
 *  - public ?array $charsets : optional list of charset strings (e.g. ["utf-8"]) or null to defer
 *
 * Notes:
 *  - The attribute is informational; it does not enforce response generation.
 *  - Media type strings should be valid MIME types; consumer code may perform
 *    further validation or normalization as required.
 */
final class Produces
{
    /**
     * Construct a Produces attribute instance.
     *
     * @param string[] $types Ordered list of media types the route may produce.
     *                        Examples: ['application/json'], ['text/html', 'application/xhtml+xml']
     * @param string[]|null $charsets Optional list of charsets applicable to the types
     *                                (e.g. ['utf-8']). When null the charset is unspecified
     *                                and should be determined by actual responses or defaults.
     */
    public function __construct(
        public array $types,
        public ?array $charsets = null,
    ) {}
}
