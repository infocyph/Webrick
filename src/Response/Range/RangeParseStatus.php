<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Response\Range;

enum RangeParseStatus: string
{
    case MALFORMED = 'malformed';
    case MULTIPLE = 'multiple';
    case NONE = 'none';
    case SATISFIABLE = 'satisfiable';
    case UNSATISFIABLE = 'unsatisfiable';
}
