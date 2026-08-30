<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Http;

/**
 * Request/session integration boundary for CSRF token persistence.
 */
interface CsrfTokenStoreInterface
{
    public function get(): ?string;

    public function put(string $token): void;
}
