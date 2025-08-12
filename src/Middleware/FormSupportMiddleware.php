<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\InputSanitizer;

/**
 * FormSupportMiddleware
 *
 * • Cheap guard to skip work when a form body is certainly absent
 * • HTML form method-override: header or _method field
 * • (Optional) sanitize form body via shared InputSanitizer
 *
 * Use together with CsrfMiddleware if you need CSRF protection.
 */
final class FormSupportMiddleware
{
    private const ATTR_F = InputSanitizerMiddleware::ATTR_F;

    public function __construct(
        private string $overrideHeader = 'X-HTTP-Method-Override',
        private bool $sanitize = true,
        private ?InputSanitizer $sanitizer = null,
    ) {
        $this->sanitizer ??= new InputSanitizer();
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        // 1) Fast exits when there cannot be a classic form body
        if (
            $req->getMethod() !== 'POST' ||
            !$this->isForm($req->getHeaderLine('content-type')) ||
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

    private function applyMethodOverride(Request $req, bool $headerOnly): Request
    {
        $new = $req->getHeaderLine($this->overrideHeader);

        if ($new === '' && !$headerOnly && $req::getMethodParamOverride() && is_array($req->getParsedBody())) {
            $new = (string)($req->getParsedBody()['_method'] ?? '');
        }

        return $new !== '' ? $req->withMethod(strtoupper($new)) : $req;
    }

    private function isForm(string $ctype): bool
    {
        $mime = strtolower(strtok($ctype, ';') ?: '');
        return $mime === 'application/x-www-form-urlencoded' || $mime === 'multipart/form-data';
    }

    /**
     * True when we can assert the body is empty:
     *   • Content-Length: 0
     *   • or both CL & TE absent (HTTP/1.0 style)
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
