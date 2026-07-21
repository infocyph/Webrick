<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Emitter\BaseEmitter;
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
