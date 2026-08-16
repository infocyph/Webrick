<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\AutoEmitter;
use Infocyph\Webrick\Response\Emitter\BaseEmitter;
use Infocyph\Webrick\Response\Emitter\DefaultEmitter;
use Infocyph\Webrick\Response\Response;

test('base emitter sends a known content length and writes the body once', function (): void {
    $emitter = new class extends BaseEmitter {
        /** @var array<string,string> */
        public array $headers = [];

        protected function finish(): void {}

        protected function headersAlreadySent(): bool
        {
            return false;
        }

        protected function removePoweredByHeader(): void {}

        protected function sendRawHeader(string $name, string $value): void
        {
            $this->headers[$name] = $value;
        }

        protected function setStatusCode(int $code): void {}
    };

    ob_start();
    $emitter->emit(Response::create('hello'), Request::fake(method: 'GET'));
    $output = ob_get_clean();

    expect($output)->toBe('hello')
        ->and($emitter->headers['Content-Length'])->toBe('5');
});

test('base emitter suppresses a head response body', function (): void {
    $emitter = new class extends BaseEmitter {
        protected function finish(): void {}

        protected function headersAlreadySent(): bool
        {
            return true;
        }
    };

    ob_start();
    $emitter->emit(Response::create('hello'), Request::fake(method: 'HEAD'));
    $output = ob_get_clean();

    expect($output)->toBe('');
});

test('base emitter suppresses response bodies forbidden by status', function (int $status): void {
    $emitter = new class extends BaseEmitter {
        protected function finish(): void {}

        protected function headersAlreadySent(): bool
        {
            return true;
        }
    };

    ob_start();
    $emitter->emit(Response::create('not-emitted', $status), Request::fake());
    $output = ob_get_clean();

    expect($output)->toBe('');
})->with([204, 304]);

test('base emitter preserves streaming chunk order', function (): void {
    $emitter = new class extends BaseEmitter {
        public string $output = '';

        protected function finish(): void {}

        protected function headersAlreadySent(): bool
        {
            return true;
        }

        protected function reduceOutputBuffering(): void {}

        protected function flush(): void {}

        protected function write(string $chunk): void
        {
            $this->output .= $chunk;
        }
    };
    $response = Response::stream(static function (): iterable {
        yield 'first';
        yield '-second';
    });

    $emitter->emit($response, Request::fake());

    expect($emitter->output)->toBe('first-second');
});

test('auto emitter gives its explicit emit argument priority over SAPI detection', function (): void {
    $picker = new ReflectionMethod(AutoEmitter::class, 'pick');
    $selected = $picker->invoke(new AutoEmitter(), null, 'default');

    expect($selected)->toBeInstanceOf(DefaultEmitter::class);
});
