<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Request\Support;

/** Explicit JSON/XML payload lifecycle state. */
enum PayloadParseState: string
{
    case INVALID = 'invalid';
    case NOT_APPLICABLE = 'not_applicable';
    case NOT_PARSED = 'not_parsed';
    case PARSED = 'parsed';
}
