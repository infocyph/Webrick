<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware\Maintenance;

/** Null means serving normally; a string is the maintenance response payload. */
interface MaintenanceStateInterface
{
    public function message(): ?string;
}
