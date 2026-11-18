<?php

declare(strict_types=1);

use Infocyph\Webrick\Support\HeaderLine;

it('keeps valid HTTP response header values unchanged', function (): void {
    expect(HeaderLine::validated('Content-Type', 'text/plain; charset=utf-8'))
        ->toBe('Content-Type: text/plain; charset=utf-8')
        ->and(HeaderLine::validated('X-Test', "before\tafter"))->toBe("X-Test: before\tafter");
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
    ['X-Test', "ok\x01bad"],
    ['X-Test', "ok\x0bbad"],
    ['X-Test', "ok\x1fbad"],
    ['X-Test', "ok\x7fbad"],
]);

it('rejects every prohibited C0 or DEL byte in HTTP field values', function (): void {
    for ($byte = 0; $byte <= 0x7f; ++$byte) {
        if ($byte === 0x09 || ($byte >= 0x20 && $byte <= 0x7e)) {
            continue;
        }

        expect(static fn(): string => HeaderLine::validated('X-Test', 'prefix' . chr($byte) . 'suffix'))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('rejects header-name delimiters and permits obs-text values without rewriting', function (): void {
    foreach ([":", " ", "\t", "\r", "\n", "(", ")", "@", "[", "]", "{", "}", "\\"] as $delimiter) {
        expect(static fn(): string => HeaderLine::validated('X' . $delimiter . 'Name', 'value'))
            ->toThrow(InvalidArgumentException::class);
    }

    expect(HeaderLine::validated('X-Bytes', "\x80\xff"))->toBe("X-Bytes: \x80\xff");
});
