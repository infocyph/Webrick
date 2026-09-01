<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

/**
 * Convenience SAPI/session adapter. The CSRF core itself never reads globals.
 * Persistent runtimes should inject a request-local session implementation.
 */
final readonly class SessionCsrfTokenStore implements CsrfTokenStoreInterface
{
    public function __construct(private string $key = '_token') {}

    public function get(): ?string
    {
        $token = $_SESSION[$this->key] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function put(string $token): void
    {
        $_SESSION[$this->key] = $token;
    }
}
