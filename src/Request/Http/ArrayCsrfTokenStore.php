<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

/**
 * In-memory token store useful for tests and request-scoped application state.
 */
final class ArrayCsrfTokenStore implements CsrfTokenStoreInterface
{
    public function __construct(private ?string $token = null) {}

    #[\Override]
    public function get(): ?string
    {
        return $this->token;
    }

    #[\Override]
    public function put(string $token): void
    {
        $this->token = $token;
    }
}
