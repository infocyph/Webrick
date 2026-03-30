<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Constraint\Registry;

describe('Constraint Registry', function () {
    it('returns embeddable regex for built-in constraints with modifiers', function () {
        $spec = Registry::getValidatorSpec('uuid');

        expect($spec)->toHaveKey('regex');

        $regex = '#\A' . $spec['regex'] . '\z#D';
        expect(\preg_match($regex, '550e8400-e29b-41d4-a716-446655440000'))->toBe(1);
    });

    it('accepts custom delimited regex with modifiers', function () {
        $name = 'custom_ci_' . \bin2hex(\random_bytes(4));
        Registry::register($name, '/^foo$/i');

        $spec = Registry::getValidatorSpec($name);
        $regex = '#\A' . $spec['regex'] . '\z#D';

        expect(\preg_match($regex, 'FOO'))->toBe(1)
            ->and(\preg_match($regex, 'bar'))->toBe(0);
    });
});

