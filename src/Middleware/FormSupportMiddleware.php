<?php

/**
 * Webrick - Form support middleware.
 *
 * Enhances traditional HTML form handling:
 * - Fast exit when a form body is certainly absent.
 * - Supports HTTP method override via header or _method field.
 * - Optional form body sanitization using a shared InputSanitizer.
 *
 * Use together with CsrfMiddleware when CSRF protection is required.
 *
 * @package Infocyph\Webrick\Middleware
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\HttpUtils;
use Infocyph\Webrick\Support\InputSanitizer;

/**
 * Middleware that provides HTML form ergonomics (method override and optional sanitization).
 */
final class FormSupportMiddleware
{
    /** Marker attribute set by InputSanitizerMiddleware for body sanitization. */
    private const ATTR_F = InputSanitizerMiddleware::ATTR_F;

    /**
     * Configure form support behavior.
     *
     * @param string               $overrideHeader Header name for method override (e.g., X-HTTP-Method-Override).
     * @param bool                 $sanitize       Whether to sanitize form bodies using the shared sanitizer.
     * @param InputSanitizer|null  $sanitizer      Optional sanitizer; defaults to a new InputSanitizer.
     */
    public function __construct(
        private readonly string $overrideHeader = 'X-HTTP-Method-Override',
        private readonly bool $sanitize = true,
        private ?InputSanitizer $sanitizer = null,
    ) {
        $this->sanitizer ??= new InputSanitizer();
    }

    /**
     * Apply method override and optionally sanitize form bodies.
     *
     * Flow:
     * 1) If not a POST with form Content-Type or explicitly empty, still honor header-based override and return.
     * 2) Otherwise apply header-based override first, then _method field if enabled and present.
     * 3) Optionally sanitize the form body if not already sanitized globally.
     *
     * @param Request $req  Incoming request.
     * @param Closure $next Next handler.
     *
     * @return Response Downstream response.
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        // 1) Fast exits when there cannot be a classic form body
        if (
            $req->getMethod() !== 'POST' ||
            !HttpUtils::isFormContentType($req->getHeaderLine('content-type')) ||
            $this->isExplicitlyEmpty($req)
        ) {
            // still honor header-based override even without a form body
            $req = $this->applyMethodOverride($req, headerOnly: true);
            return $next($req);
        }

        // 2) Apply method override (header first, then _method if present)
        $req = $this->applyMethodOverride($req, headerOnly: false);

        // 3) Optional sanitize (only if not already done globally)
        if ($this->sanitize && !$req->getAttribute(self::ATTR_F, false)) {
            $body = $req->getParsedBody();
            if (is_array($body)) {
                $req = $req
                    ->withParsedBody($this->sanitizer->sanitizeArray($body))
                    ->withAttribute(self::ATTR_F, true);
            }
        }

        return $next($req);
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /**
     * Apply method override from header or, if allowed, from the _method form field.
     *
     * @param Request $req
     * @param bool    $headerOnly When true, do not consult form field override.
     *
     * @return Request Possibly modified request with overridden method.
     */
    private function applyMethodOverride(Request $req, bool $headerOnly): Request
    {
        $new = $req->getHeaderLine($this->overrideHeader);

        if ($new === '' && !$headerOnly && $req::getMethodParamOverride() && is_array($req->getParsedBody())) {
            $new = (string)($req->getParsedBody()['_method'] ?? '');
        }

        return $new !== '' ? $req->withMethod(strtoupper($new)) : $req;
    }

    /**
     * Determine whether the request body is explicitly empty.
     *
     * Considered empty when:
     * - Content-Length is "0", or
     * - Both Content-Length and Transfer-Encoding are absent (HTTP/1.0 style),
     *   or Transfer-Encoding is explicitly "identity".
     *
     * @param Request $req
     *
     * @return bool True if the body can be considered empty.
     */
    private function isExplicitlyEmpty(Request $req): bool
    {
        $cl = trim($req->getHeaderLine('content-length'));
        if ($cl !== '') {
            return (int)$cl === 0;
        }

        $te = strtolower($req->getHeaderLine('transfer-encoding'));
        return $te === '' || $te === 'identity';
    }
}