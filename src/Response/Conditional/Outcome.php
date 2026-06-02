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
final readonly class Outcome
{
    public const int FAIL = 2;

    public const int HIT = 1;

    /** generic constants – validators decide the exact HTTP code */
    public const int PASS = 0;

    /**
     * Creates a new Outcome.
     *
     * @param int $state PASS, HIT or FAIL
     * @param int $http 0 when PASS, otherwise 3xx/4xx HTTP status code
     * @param array<string,string> $headers Optional extra response headers that must be echoed back
     *                                      when code ≠ PASS (ETag, Last-Modified, …).
     */
    public function __construct(
        public int $state,   // PASS / HIT / FAIL
        public int $http,    // 0 when PASS, otherwise 3xx/4xx
        /** @var array<string,string> */
        public array $headers = [],
    ) {}
}
