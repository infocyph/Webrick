<?php
// src/Response/Emitter/CliServerEmitter.php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Emitter;

/** PHP built-in server uses header()/echo the same way. */
final class CliServerEmitter extends BaseEmitter
{
}
