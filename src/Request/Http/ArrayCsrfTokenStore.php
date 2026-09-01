<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

/**
 * In-memory token store useful for tests and request-scoped application state.
 */
final class ArrayCsrfTokenStore implements CsrfTokenStoreInterface
{
    public function __construct(private ?string $token = null) {}

    public function get(): ?string
    {
        return $this->token;
    }

    public function put(string $token): void
    {
        $this->token = $token;
    }
}
