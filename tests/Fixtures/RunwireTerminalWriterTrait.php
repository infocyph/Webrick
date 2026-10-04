<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Fixture;

use Closure;
use Infocyph\Runwire\Http\ResponseWriterInterface;

trait RunwireTerminalWriterTrait
{
    /** @var list<Closure(ResponseWriterInterface): void> */
    private array $terminalObservers = [];

    private bool $terminalNotified = false;

    public function onTerminal(callable $callback): ResponseWriterInterface
    {
        $observer = Closure::fromCallable($callback);
        if ($this->terminalNotified || $this->isEnded()) {
            $this->terminalNotified = true;
            $observer($this);

            return $this;
        }

        $this->terminalObservers[] = $observer;

        return $this;
    }

    protected function notifyTerminal(): void
    {
        if ($this->terminalNotified) {
            return;
        }

        $this->terminalNotified = true;
        $observers = $this->terminalObservers;
        $this->terminalObservers = [];

        foreach ($observers as $observer) {
            $observer($this);
        }
    }
}
