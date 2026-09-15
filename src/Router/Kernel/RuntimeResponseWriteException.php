<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Kernel;

use RuntimeException;
use Throwable;

/** @internal Prevents transport-output failures from being rendered as a second HTTP response. */
final class RuntimeResponseWriteException extends RuntimeException
{
    public function __construct(public readonly Throwable $failure)
    {
        parent::__construct('Runtime response output failed.', previous: $failure);
    }
}
