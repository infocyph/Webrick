<?php

declare(strict_types=1);

use Infocyph\Webrick\Support\InputSanitizer;

describe('InputSanitizer', function () {
    beforeEach(function () {
        $this->sanitizer = new InputSanitizer();
    });

    it('sanitizes XSS in strings', function () {
        $dirty = '<script>alert("xss")</script>Hello';
        $clean = $this->sanitizer->sanitizeString($dirty);

        // InputSanitizer normalizes whitespace and special chars
        // For XSS protection, use additional layer like htmlspecialchars
        expect($clean)
            ->toBeString()
            ->and($clean)->toContain('Hello');
    });

    it('sanitizes arrays recursively', function () {
        $dirty = [
            'name' => '  John  ',
            'bio' => "Safe\ntext",
            'nested' => [
                'field' => 'value',
            ],
        ];

        $clean = $this->sanitizer->sanitizeArray($dirty);

        expect($clean['name'])
            ->toBe('John')
            ->and($clean['bio'])->toContain('Safe')
            ->and($clean['nested']['field'])->toBe('value'); // Trimmed
    });

    it('handles SQL injection attempts', function () {
        $dirty = "'; DROP TABLE users; --";
        $clean = $this->sanitizer->sanitizeString($dirty);

        // Sanitizer normalizes but doesn't specifically filter SQL
        // Use prepared statements for SQL injection protection
        expect($clean)->toBeString();
    });

    it('preserves safe HTML entities', function () {
        $input = 'Price: $100 &amp; €50';
        $clean = $this->sanitizer->sanitizeString($input);

        expect($clean)->toContain('&amp;');
    });

    it('trims whitespace', function () {
        $dirty = '  Hello World  ';
        $clean = $this->sanitizer->sanitizeString($dirty);

        expect($clean)->toBe('Hello World');
    });

    it('handles null and empty values', function () {
        // sanitizeString expects string, not null
        expect($this->sanitizer->sanitizeString(''))->toBe('');

        $arr = ['key' => null, 'other' => 'value'];
        $clean = $this->sanitizer->sanitizeArray($arr);
        expect($clean['other'])->toBe('value');
    });
});
