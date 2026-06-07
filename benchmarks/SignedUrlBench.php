<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Infocyph\Webrick\Benchmarks\Support\BenchmarkSupport;
use PhpBench\Attributes as Bench;

#[Bench\Groups(['signed-url'])]
#[Bench\Iterations(5)]
#[Bench\Revs(5000)]
#[Bench\Warmup(1)]
final class SignedUrlBench
{
    public function benchAbsoluteSign(): void
    {
        BenchmarkSupport::generateAbsoluteSignedUrl();
    }

    public function benchAbsoluteUntil(): void
    {
        BenchmarkSupport::generateAbsoluteUntilUrl();
    }

    public function benchAbsoluteVerify(): void
    {
        BenchmarkSupport::verifyAbsoluteSignedUrl();
    }

    public function benchRelativeSign(): void
    {
        BenchmarkSupport::generateRelativeTemporaryUrl();
    }

    public function benchRelativeVerify(): void
    {
        BenchmarkSupport::verifyRelativeSignedUrl();
    }
}
