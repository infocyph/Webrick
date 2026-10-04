<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Runtime\Http;

use RuntimeException;

/** @internal Expected transport cancellation/closure that must not poison a persistent worker. */
final class RunwireTransportCancellation extends RuntimeException {}
