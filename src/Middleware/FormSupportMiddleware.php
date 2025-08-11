<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * FormSupportMiddleware
 *
 * Merges:
 *   • MethodOverrideMiddleware (header + `_method` field for classic POST forms)
 *   • CsrfMiddleware (unsafe verbs)
 *   • TrimStringsMiddleware + ConvertEmptyStringsToNullMiddleware
 *   • GuardEmptyPost fast-path (don’t touch body when it's certainly empty)
 *
 * Order: place this BEFORE routing so overrides affect route matching.
 */
final class FormSupportMiddleware
{
    /** Public flag (may be set on the Request) to bypass CSRF for trusted callers */
    public const BYPASS_ATTR = '_csrf_bypass';
    /** Private marker you set via markTrusted() to enable the BYPASS_ATTR */
    private const TRUST_MARKER = '__csrf_internal__';

    public function __construct(
        private string $methodHeader = 'X-HTTP-Method-Override',
        private bool $trimAndNullify = true,          // apply to query + form bodies
    ) {}

    /**
     * Mark a Request as trusted (e.g. tests, job replay). Together with BYPASS_ATTR=true
     * CSRF checks are skipped securely.
     */
    public static function markTrusted(Request $r): Request
    {
        return $r->withAttribute(self::TRUST_MARKER, true);
    }

    public function __invoke(Request $req, Closure $next): Response
    {
        /* ───── 0) header-based method override (cheap, before routing) ───── */
        $req = $this->applyHeaderOverride($req);

        /* ───── 1) quick decision: is there a classic form body to touch? ─── */
        $ctype = strtolower($req->getHeaderLine('content-type'));
        $hasFormBody = $this->isForm($ctype) && !$this->isExplicitlyEmpty($req);

        /* ───── 2) optional body-field method override (needs form parse) ─── */
        if ($hasFormBody && Request::getMethodParamOverride()) {
            $req = $this->applyBodyOverride($req);
        }

        /* ───── 3) trim + ''→null (query always; body only when array) ───── */
        if ($this->trimAndNullify) {
            $req = $this->sanitizeInputs($req, $hasFormBody);
        }

        /* ───── 4) CSRF (after final method is known) ────────────────────── */
        if ($this->needsCsrf($req)) {
            if ($req->getAttribute(self::TRUST_MARKER, false) === true &&
                $req->getAttribute(self::BYPASS_ATTR, false) === true) {
                // secure internal bypass
            } elseif (!$req->matchesCsrfToken()) {
                return new Response(
                    status: 419,
                    headers: ['Content-Type' => 'text/plain; charset=utf-8'],
                    body: new Stream('CSRF token mismatch.'),
                );
            }
        }

        /* ───── 5) downstream ────────────────────────────────────────────── */
        return $next($req);
    }

    /* ───────────────────────── helpers ───────────────────────── */

    private function applyHeaderOverride(Request $r): Request
    {
        $new = $r->getHeaderLine($this->methodHeader);
        if ($new !== '') {
            $r = $r->withMethod(strtoupper($new));
        }
        return $r;
    }

    private function applyBodyOverride(Request $r): Request
    {
        $body = $r->getParsedBody();
        if (is_array($body) && ($m = ($body['_method'] ?? '')) !== '') {
            $r = $r->withMethod(strtoupper((string)$m));
        }
        return $r;
    }

    private function sanitizeInputs(Request $r, bool $hasFormBody): Request
    {
        // Body
        if ($hasFormBody) {
            $body = $r->getParsedBody();
            if (is_array($body)) {
                $body = $this->trimRecursive($body);
                $body = $this->nullifyRecursive($body);
                $r = $r->withParsedBody($body);
            }
        }

        // Query (always cheap)
        $q = $r->getQueryParams();
        if ($q) {
            $q = $this->trimRecursive($q);
            $q = $this->nullifyRecursive($q);
            $r = $r->withQueryParams($q);
        }

        return $r;
    }

    private function needsCsrf(Request $r): bool
    {
        $m = strtoupper($r->getMethod());
        // Protect unsafe verbs
        return in_array($m, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function isForm(string $ctype): bool
    {
        $mime = strtolower(strtok($ctype, ';') ?: '');
        return $mime === 'application/x-www-form-urlencoded'
            || $mime === 'multipart/form-data';
    }

    /**
     * True when we can assert the body is empty:
     *  • Content-Length: 0
     *  • OR both CL & TE missing/identity
     */
    private function isExplicitlyEmpty(Request $r): bool
    {
        $cl = trim($r->getHeaderLine('content-length'));
        if ($cl !== '') {
            return ((int)$cl) === 0;
        }
        $te = strtolower($r->getHeaderLine('transfer-encoding'));
        return $te === '' || $te === 'identity';
    }

    private function trimRecursive(array $data): array
    {
        array_walk_recursive($data, static function (&$v): void {
            if (is_string($v)) {
                $v = trim($v);
            }
        });
        return $data;
    }

    private function nullifyRecursive(array $data): array
    {
        array_walk_recursive($data, static function (&$v): void {
            if ($v === '') {
                $v = null;
            }
        });
        return $data;
    }
}
