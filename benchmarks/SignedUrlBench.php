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
    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function benchAbsoluteSign(): void
    {
        BenchmarkSupport::generateAbsoluteSignedUrl();
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function benchAbsoluteUntil(): void
    {
        BenchmarkSupport::generateAbsoluteUntilUrl();
    }

    /**
     * @throws \Infocyph\Webrick\Exceptions\HttpException
     * @throws \InvalidArgumentException
     */
    public function benchAbsoluteVerify(): void
    {
        BenchmarkSupport::verifyAbsoluteSignedUrl();
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function benchRelativeSign(): void
    {
        BenchmarkSupport::generateRelativeTemporaryUrl();
    }

    /**
     * @throws \Infocyph\Webrick\Exceptions\HttpException
     * @throws \InvalidArgumentException
     */
    public function benchRelativeVerify(): void
    {
        BenchmarkSupport::verifyRelativeSignedUrl();
    }
}
