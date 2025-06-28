<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Response\Conditional;

/**
 * Immutable result for any pre-processing step that might
 * short-circuit normal route execution.
 *
 *  code     PASS  → continue pipeline
 *           HIT   → send cached / safe representation (e.g., 304, 206)
 *           FAIL  → pre-condition failed (e.g., 412)
 *
 *  headers  Optional extra response headers that must be echoed back
 *           when code ≠ PASS (ETag, Last-Modified, …).
 */
final class Outcome
{
    /** generic constants – validators decide the exact HTTP code */
    public const PASS = 0;
    public const HIT  = 1;
    public const FAIL = 2;

    public function __construct(
        public readonly int   $state,   // PASS / HIT / FAIL
        public readonly int   $http,    // 0 when PASS, otherwise 3xx/4xx
        public readonly array $headers = []
    ) {}
}
