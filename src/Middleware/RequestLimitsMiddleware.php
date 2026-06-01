<?php

/**
 * Webrick - Request limits middleware.
 *
 * Enforces hard caps on incoming request header size (431), header field count (431),
 * and body size (413). Safe for HTTP/2 (never emits hop-by-hop "Connection" header
 * on H2). Body size enforcement is based on Content-Length; when Transfer-Encoding
 * (e.g., chunked) is present, the middleware does not pre-reject because the length
 * is not known up front.
 */

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use InvalidArgumentException;

/**
 * Apply request caps for headers (bytes and field count) and body size.
 *
 * Behavior notes:
 * - Header bytes and header field count apply to every request.
 * - Body size applies to configured HTTP methods only and relies on Content-Length.
 * - If Transfer-Encoding is present (other than "trailers"), body size is not pre-enforced here.
 * - On violation, responds with appropriate status (431/413) and may add "Connection: close"
 *   for HTTP/1.x only.
 */
final readonly class RequestLimitsMiddleware
{
    /**
     * Construct the middleware with limit settings.
     *
     * @param int $maxHeaderBytes Maximum total header bytes; 0 disables the byte check.
     * @param int $maxHeaderCount Maximum number of header fields; 0 disables the count check
     *                            (fields counted as each header value line).
     * @param int|null $maxBodyBytes Maximum allowed body bytes; null uses ini_get('post_max_size').
     * @param array<int,string> $bodyLimitVerbs HTTP methods to which the body limit applies (uppercased compare).
     * @param bool $violateOnUnknownBody When true and neither Content-Length nor transfer-coding is present,
     *                                   treat as violation for configured verbs.
     */
    public function __construct(
        private int $maxHeaderBytes = 8192,
        private int $maxHeaderCount = 100,
        private ?int $maxBodyBytes = null,
        private array $bodyLimitVerbs = [
            HttpMethodEnum::POST->value,
            HttpMethodEnum::PUT->value,
            HttpMethodEnum::PATCH->value,
            HttpMethodEnum::DELETE->value,
        ],
        private bool $violateOnUnknownBody = true,
    ) {
        if ($this->maxHeaderBytes < 0) {
            throw new InvalidArgumentException('maxHeaderBytes must be >= 0.');
        }
        if ($this->maxHeaderCount < 0) {
            throw new InvalidArgumentException('maxHeaderCount must be >= 0.');
        }
        if ($this->maxBodyBytes !== null && $this->maxBodyBytes < 0) {
            throw new InvalidArgumentException('maxBodyBytes must be >= 0 when provided.');
        }
    }

    /**
     * Enforce header/body limits and return 431/413 when violated.
     *
     * Steps:
     * 0) Enforce header field count (431).
     * 1) Enforce header byte size (431).
     * 2) Enforce body size (413) based on Content-Length; do not pre-reject when Transfer-Encoding (e.g., chunked).
     *
     * @param Request $req Incoming request.
     * @param Closure(Request):Response $next
     * @return Response Response from next handler or an error response on violation.
     */
    public function __invoke(Request $req, Closure $next): Response
    {
        $resp = $this->rejectForHeaderFieldCount($req);
        if ($resp !== null) {
            return $resp;
        }

        $resp = $this->rejectForHeaderBytes($req);
        if ($resp !== null) {
            return $resp;
        }

        $resp = $this->rejectForBodyLimit($req);
        if ($resp !== null) {
            return $resp;
        }

        return $next($req);
    }

    /**
     * Convert a php.ini size string (e.g., "8M", "1G") to bytes.
     *
     * @param string|false $val Value returned by ini_get().
     * @return int Byte count (0 for empty/false).
     */
    private static function phpIniBytes(string|false $val): int
    {
        if ($val === false) {
            return 0;
        }
        $val = \trim($val);
        if ($val === '') {
            return 0;
        }
        $unit = \strtolower(substr($val, -1));
        $num = (int) $val;

        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $val,
        };
    }

    private function appliesBodyLimitToMethod(Request $req): bool
    {
        return \in_array(HttpMethodEnum::normalize($req->getMethod()), $this->bodyLimitVerbs, true);
    }

    private function hasTransferCoding(Request $req): bool
    {
        $teLine = \strtolower($req->getHeaderLine('Transfer-Encoding'));
        if ($teLine === '') {
            return false;
        }

        foreach (\explode(',', $teLine) as $tok) {
            $tok = \trim($tok);
            if ($tok !== '' && $tok !== 'identity' && $tok !== 'trailers') {
                return true;
            }
        }

        return false;
    }

    private function headerValueLength(mixed $value): int
    {
        if (\is_string($value)) {
            return \strlen($value);
        }
        if (\is_scalar($value)) {
            return \strlen((string) $value);
        }

        return 0;
    }

    /**
     * Parse Content-Length as a strict non-negative integer.
     *
     * Returns null for invalid grammar; returns PHP_INT_MAX when numeric but out of platform range.
     */
    private function parseContentLength(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $value === false ? PHP_INT_MAX : (int) $value;
    }

    private function payloadTooLargeResponse(Request $req): Response
    {
        $resp = Response::plaintext(
            'Payload exceeds maximum allowed size.',
            StatusEnum::PAYLOAD_TOO_LARGE->value,
        );

        return $this->withConnCloseIfHttp1($req, $resp);
    }

    private function rejectForBodyLimit(Request $req): ?Response
    {
        $limit = $this->resolveBodyLimit();
        if ($limit <= 0 || !$this->appliesBodyLimitToMethod($req)) {
            return null;
        }

        if ($this->hasTransferCoding($req)) {
            return null;
        }

        $cl = trim($req->getHeaderLine('Content-Length'));
        if ($cl === '') {
            return $this->violateOnUnknownBody
                ? $this->payloadTooLargeResponse($req)
                : null;
        }

        $len = $this->parseContentLength($cl);
        if ($len === null) {
            $resp = Response::plaintext('Invalid Content-Length header.', StatusEnum::BAD_REQUEST->value);

            return $this->withConnCloseIfHttp1($req, $resp);
        }

        return $len > $limit ? $this->payloadTooLargeResponse($req) : null;
    }

    private function rejectForHeaderBytes(Request $req): ?Response
    {
        if ($this->maxHeaderBytes <= 0) {
            return null;
        }

        if ($this->totalHeaderBytes($req) <= $this->maxHeaderBytes) {
            return null;
        }

        $resp = Response::plaintext('Request headers too large', StatusEnum::REQUEST_HEADER_FIELDS_TOO_LARGE->value);

        return $this->withConnCloseIfHttp1($req, $resp);
    }

    private function rejectForHeaderFieldCount(Request $req): ?Response
    {
        if ($this->maxHeaderCount <= 0) {
            return null;
        }

        if ($this->totalHeaderFields($req) <= $this->maxHeaderCount) {
            return null;
        }

        $resp = Response::plaintext('Too many header fields', StatusEnum::REQUEST_HEADER_FIELDS_TOO_LARGE->value);

        return $this->withConnCloseIfHttp1($req, $resp);
    }

    /* ───────────────────────── helpers ─────────────────────────── */

    /**
     * Resolve the body size limit in bytes.
     *
     * Uses $this->maxBodyBytes when set; otherwise converts ini "post_max_size" to bytes.
     *
     * @return int Body limit in bytes (0 means disabled).
     */
    private function resolveBodyLimit(): int
    {
        if ($this->maxBodyBytes !== null) {
            return $this->maxBodyBytes;
        }

        return self::phpIniBytes(\ini_get('post_max_size'));
    }

    /**
     * Compute a conservative byte count for all headers.
     *
     * Counts "Name: value" length for each header value line or raw line in flat-list mode.
     *
     * @param Request $r The request.
     * @return int Total header bytes.
     */
    private function totalHeaderBytes(Request $r): int
    {
        $sum = 0;
        $all = $r->getHeaders(); // supports both map or flat list forms

        foreach ($all as $name => $val) {
            if (\is_int($name)) {
                // flat list of raw header lines
                $sum += $this->headerValueLength($val);

                continue;
            }
            $values = \is_array($val) ? $val : [$val];
            foreach ($values as $v) {
                $sum += \strlen($name) + 2 + $this->headerValueLength($v); // "Name: value"
            }
        }

        return $sum;
    }

    /**
     * Count total header fields (each value counts as one field).
     *
     * Example: "Set-Cookie" repeated 5 times + "Accept: a,b" (still 1 field here since
     * your Request flattens repeated-name values as an array).
     *
     * @param Request $r The request.
     * @return int Number of header fields.
     */
    private function totalHeaderFields(Request $r): int
    {
        $all = $r->getHeaders();

        $count = 0;
        foreach ($all as $name => $val) {
            if (\is_int($name)) {
                // flat list (raw lines)
                $count++;

                continue;
            }
            $values = \is_array($val) ? $val : [$val];
            $count += \count($values);
        }

        return $count;
    }

    /**
     * Add "Connection: close" only for HTTP/1.x responses; never for HTTP/2.
     *
     * @param Request $req The incoming request (for protocol detection).
     * @param Response $resp The response to augment when applicable.
     * @return Response Response with "Connection: close" for HTTP/1.x; unchanged for HTTP/2.
     */
    private function withConnCloseIfHttp1(Request $req, Response $resp): Response
    {
        $serverProtocol = $req->getServerParams()['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
        $proto = \is_string($serverProtocol) ? strtoupper($serverProtocol) : 'HTTP/1.1';
        if (\str_starts_with($proto, 'HTTP/1.')) {
            return $resp->withSmartHeader('Connection', 'close');
        }

        return $resp;
    }
}
