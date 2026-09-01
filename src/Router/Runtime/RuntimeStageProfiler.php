<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Router\Runtime;

/**
 * Opt-in high-resolution profiler for production bootstrap/request stages.
 *
 * The profiler is never created by Webrick itself. Hosts and benchmark harnesses
 * explicitly pass one when diagnostics are required, keeping hrtime() and sample
 * collection completely off the normal production path.
 */
final class RuntimeStageProfiler
{
    private int $last;

    /** @var array<string,int> */
    private array $nanoseconds = [];

    public function __construct()
    {
        $this->reset();
    }

    public function mark(string $stage): void
    {
        if ($stage === '') {
            throw new \InvalidArgumentException('Runtime profiler stage must not be empty.');
        }

        $now = hrtime(true);
        $this->nanoseconds[$stage] = ($this->nanoseconds[$stage] ?? 0) + ($now - $this->last);
        $this->last = $now;
    }

    /** @return array<string,float> */
    public function milliseconds(): array
    {
        $samples = [];
        foreach ($this->nanoseconds as $stage => $nanoseconds) {
            $samples[$stage] = $nanoseconds / 1_000_000;
        }

        return $samples;
    }

    /** @return array<string,int> */
    public function nanoseconds(): array
    {
        return $this->nanoseconds;
    }

    public function reset(): void
    {
        $this->nanoseconds = [];
        $this->last = hrtime(true);
    }
}
