<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

use Infocyph\Webrick\Request\Request;

/**
 * CSRF token service with injected persistence.
 *
 * Proof is accepted from explicit headers or the parsed request body. Cookies
 * are transport only and are never accepted as proof. Query-string tokens are
 * disabled by default and must be explicitly enabled.
 */
final readonly class Csrf
{
    private const int TOKEN_BYTES = 32;

    public function __construct(
        private CsrfTokenStoreInterface $store,
        private bool $allowQueryToken = false,
        private string $field = '_token',
    ) {}

    public static function withSession(string $key = '_token', bool $allowQueryToken = false): self
    {
        return new self(new SessionCsrfTokenStore($key), $allowQueryToken, $key);
    }

    public function maskedToken(): string
    {
        $mask = bin2hex(random_bytes(self::TOKEN_BYTES));

        return $mask . hash_hmac('sha256', $mask, $this->token());
    }

    public function matches(Request $req): bool
    {
        return $this->matchesValue($this->extractFromRequest($req));
    }

    public function matchesValue(?string $sent): bool
    {
        $stored = $this->store->get();
        if ($sent === null || $sent === '' || $stored === null || $stored === '') {
            return false;
        }

        $hexLen = self::TOKEN_BYTES * 2;
        if (strlen($sent) === $hexLen * 2 && strlen($stored) === $hexLen) {
            $mask = substr($sent, 0, $hexLen);
            $digest = substr($sent, $hexLen);

            return self::isHex($mask)
                && self::isHex($digest)
                && hash_equals($digest, hash_hmac('sha256', $mask, $stored));
        }

        return hash_equals($stored, $sent);
    }

    public function token(): string
    {
        $stored = $this->store->get();
        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $this->store->put($token);

        return $token;
    }

    private static function isHex(string $value): bool
    {
        return $value !== '' && preg_match('/\A[0-9a-f]+\z/iD', $value) === 1;
    }

    private function extractFromRequest(Request $req): ?string
    {
        $header = $req->getHeaderLine('X-CSRF-TOKEN');
        if ($header === '') {
            $header = $req->getHeaderLine('X-XSRF-TOKEN');
        }
        if ($header !== '') {
            return $header;
        }

        $body = $req->getParsedBody();
        if (is_array($body)) {
            $bodyToken = $body[$this->field] ?? null;
            if (is_string($bodyToken) && $bodyToken !== '') {
                return $bodyToken;
            }
        }

        if (!$this->allowQueryToken) {
            return null;
        }

        parse_str($req->getUri()->getQuery(), $query);
        $queryToken = $query[$this->field] ?? null;

        return is_string($queryToken) && $queryToken !== '' ? $queryToken : null;
    }
}
