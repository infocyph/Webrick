<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Exceptions;

/**
 * Contract for framework-level HTTP control-flow exceptions.
 */
interface HttpExceptionInterface
{
    /**
     * @return array<string,string>
     */
    public function getHeaders(): array;

    public function getPublicMessage(): string;

    public function getStatusCode(): int;
}
