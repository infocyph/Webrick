<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Constants\HttpMethodEnum;
use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Support\HttpUtils;
use Infocyph\Webrick\Support\StreamUtil;
use RuntimeException;

/** Strict development-time response validator aligned with emitted HTTP semantics. */
final readonly class ResponseLinterMiddleware
{
    public const int BODY_REQUIRES_CTYPE = 0b00001;

    public const int COMPRESSED_NEEDS_VARY = 0b00100;

    public const int CONTENT_LENGTH_MATCH = 0b10000;

    /**
     * Kept for source compatibility. Strong ETags are legal for encoded representations
     * when they identify the encoded octets, so this flag intentionally adds no rejection.
     */
    public const int ETAG_WEAK_WHEN_ENCODING = 0b01000;

    public const int NO_BODY_STATUSES = 0b00010;

    private int $checks;

    public function __construct(int|bool $checks = false)
    {
        $this->checks = is_bool($checks)
            ? ($checks ? (
                self::BODY_REQUIRES_CTYPE
                | self::NO_BODY_STATUSES
                | self::COMPRESSED_NEEDS_VARY
                | self::CONTENT_LENGTH_MATCH
            ) : 0)
            : $checks;
    }

    /** @param Closure(Request):Response $next */
    public function __invoke(Request $req, Closure $next): Response
    {
        $resp = $next($req);
        if ($this->checks === 0) {
            return $resp;
        }

        $len = StreamUtil::byteLength($resp->getBody(), -1);

        if (($this->checks & self::BODY_REQUIRES_CTYPE) !== 0) {
            $this->assertContentTypeIfBody($resp, $len);
        }
        if (($this->checks & self::NO_BODY_STATUSES) !== 0) {
            $this->assertNoBodyOnStatuses($resp, $len);
        }
        if (($this->checks & self::COMPRESSED_NEEDS_VARY) !== 0) {
            $this->assertVaryOnCompressed($resp);
        }
        if (($this->checks & self::CONTENT_LENGTH_MATCH) !== 0) {
            $this->assertContentLengthMatches($req, $resp, $len);
        }

        return $resp;
    }

    private function assertContentLengthMatches(Request $req, Response $resp, int $len): void
    {
        if ($resp->hasHeader('Transfer-Encoding')) {
            return;
        }

        $code = $resp->getStatusCode();
        $line = trim($resp->getHeaderLine('Content-Length'));

        if (($code >= 100 && $code < 200) || $code === StatusEnum::NO_CONTENT->value) {
            if ($line !== '') {
                throw new RuntimeException("Linter: Content-Length is forbidden on {$code}");
            }

            return;
        }

        if ($code === StatusEnum::RESET_CONTENT->value) {
            if ($line !== '' && $line !== '0') {
                throw new RuntimeException('Linter: 205 Content-Length must be 0 when present');
            }

            return;
        }

        if (
            $code === StatusEnum::NOT_MODIFIED->value
            || HttpMethodEnum::normalize($req->getMethod()) === HttpMethodEnum::HEAD->value
            || $line === ''
        ) {
            return;
        }

        $declared = HttpUtils::parseUnsignedDecimal($line);
        if ($declared === null) {
            throw new RuntimeException('Linter: invalid Content-Length header');
        }
        if ($len >= 0 && $declared !== $len) {
            throw new RuntimeException(sprintf(
                'Linter: Content-Length (%d) does not match body bytes (%d)',
                $declared,
                $len,
            ));
        }
    }

    private function assertContentTypeIfBody(Response $resp, int $len): void
    {
        if ($len > 0 && !StatusEnum::isEmptyCode($resp->getStatusCode()) && $resp->getHeaderLine('Content-Type') === '') {
            throw new RuntimeException('Linter: non-empty body without Content-Type');
        }
    }

    private function assertNoBodyOnStatuses(Response $resp, int $len): void
    {
        if ($len <= 0 || !StatusEnum::isEmptyCode($resp->getStatusCode())) {
            return;
        }

        throw new RuntimeException("Linter: body not allowed on {$resp->getStatusCode()}");
    }

    private function assertVaryOnCompressed(Response $resp): void
    {
        if (!$resp->hasHeader('Content-Encoding')) {
            return;
        }
        if (!$this->lineHasToken($resp->getHeaderLine('Vary'), 'accept-encoding')) {
            throw new RuntimeException('Linter: compressed reply missing Vary: Accept-Encoding');
        }
    }

    private function lineHasToken(string $line, string $needleLower): bool
    {
        if ($line === '') {
            return false;
        }

        return array_any(
            explode(',', $line),
            static fn(string $token): bool => strtolower(trim($token)) === $needleLower,
        );
    }
}
