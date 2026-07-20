<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Infocyph\Webrick\Request\Core\Stream;
use Infocyph\Webrick\Support\Etag;
use PhpBench\Attributes as Bench;

#[Bench\Groups(['etag'])]
#[Bench\Iterations(5)]
#[Bench\Revs(1000)]
#[Bench\Warmup(1)]
final class EtagBench
{
    private Stream $stream;

    public function setUp(): void
    {
        $this->stream = new Stream(str_repeat('a', 65_536));
    }

    #[Bench\BeforeMethods('setUp')]
    #[Bench\ParamProviders('provideAlgorithms')]
    public function benchHashStream(array $params): void
    {
        Etag::fromStream($this->stream, algo: $params['algorithm']);
    }

    /** @return iterable<string, array{algorithm:string}> */
    public function provideAlgorithms(): iterable
    {
        yield 'xxh128' => ['algorithm' => 'xxh128'];
        yield 'sha256' => ['algorithm' => 'sha256'];
        yield 'sha3-256' => ['algorithm' => 'sha3-256'];
    }
}
