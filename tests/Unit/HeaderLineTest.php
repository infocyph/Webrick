<?php

declare(strict_types=1);

use Infocyph\Webrick\Support\HeaderLine;

it('keeps valid HTTP response header values unchanged', function (): void {
    expect(HeaderLine::validated('Content-Type', 'text/plain; charset=utf-8'))
        ->toBe('Content-Type: text/plain; charset=utf-8');
});

it('rejects invalid HTTP header names and response-splitting bytes', function (string $name, string $value): void {
    expect(fn(): string => HeaderLine::validated($name, $value))
        ->toThrow(InvalidArgumentException::class);
})->with([
    ['', 'value'],
    ['Bad Name', 'value'],
    ["X-Test\r\nInjected", 'value'],
    ['X-Test', "ok\r\nX-Injected: yes"],
    ['X-Test', "ok\nX-Injected: yes"],
    ['X-Test', "ok\0bad"],
]);
