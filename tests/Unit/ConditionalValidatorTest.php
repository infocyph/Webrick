<?php

declare(strict_types=1);

use Infocyph\Webrick\Constants\StatusEnum;
use Infocyph\Webrick\Response\Conditional\ConditionalValidator;
use Infocyph\Webrick\Response\Conditional\Outcome;

describe('ConditionalValidator', function () {
    it('requires strong tags for If-Match and If-Range', function () {
        $validator = new ConditionalValidator('W/"tag"');
        $ifMatch = $validator->evaluate(mockRequest('GET', '/', ['If-Match' => 'W/"tag"']));
        $ifRange = mockRequest('GET', '/', ['If-Range' => 'W/"tag"']);

        expect($ifMatch->state)
            ->toBe(Outcome::FAIL)
            ->and($ifMatch->http)->toBe(StatusEnum::PRECONDITION_FAILED->value)
            ->and($validator->isRangeFresh($ifRange))->toBeFalse();
    });

    it('uses case-sensitive weak comparison for If-None-Match', function () {
        $validator = new ConditionalValidator('"Tag"');
        $weakMatch = $validator->evaluate(mockRequest('GET', '/', ['If-None-Match' => 'W/"Tag"']));
        $caseMismatch = $validator->evaluate(mockRequest('GET', '/', ['If-None-Match' => 'W/"tag"']));

        expect($weakMatch->state)
            ->toBe(Outcome::HIT)
            ->and($caseMismatch->state)->toBe(Outcome::PASS);
    });

    it('recognizes wildcard validators', function () {
        $validator = new ConditionalValidator('"tag"');
        $outcome = $validator->evaluate(mockRequest('GET', '/', ['If-None-Match' => '*']));

        expect($outcome->state)
            ->toBe(Outcome::HIT)
            ->and($outcome->http)->toBe(StatusEnum::NOT_MODIFIED->value);
    });

    it('returns 412 when If-None-Match matches on an unsafe method', function () {
        $validator = new ConditionalValidator('"tag"');
        $outcome = $validator->evaluate(mockRequest('PUT', '/', ['If-None-Match' => 'W/"tag"']));

        expect($outcome->state)
            ->toBe(Outcome::FAIL)
            ->and($outcome->http)->toBe(StatusEnum::PRECONDITION_FAILED->value);
    });

    it('ignores If-Unmodified-Since when If-Match is present and succeeds', function () {
        $validator = new ConditionalValidator('"tag"', 1_700_000_000);
        $outcome = $validator->evaluate(mockRequest('PUT', '/', [
            'If-Match' => '"tag"',
            'If-Unmodified-Since' => 'Thu, 01 Jan 1970 00:00:00 GMT',
        ]));

        expect($outcome->state)
            ->toBe(Outcome::PASS)
            ->and($outcome->http)->toBe(0);
    });

    it('gives If-None-Match precedence over If-Modified-Since', function () {
        $validator = new ConditionalValidator('"tag"', 1_700_000_000);
        $outcome = $validator->evaluate(mockRequest('GET', '/', [
            'If-None-Match' => '"different"',
            'If-Modified-Since' => 'Wed, 01 Jan 2031 00:00:00 GMT',
        ]));

        expect($outcome->state)
            ->toBe(Outcome::PASS)
            ->and($outcome->http)->toBe(0);
    });

    it('preserves the unix epoch as a valid last-modified value', function () {
        $validator = new ConditionalValidator(lastModified: 0);
        $outcome = $validator->evaluate(mockRequest('GET', '/', [
            'If-Modified-Since' => 'Thu, 01 Jan 1970 00:00:00 GMT',
        ]));

        expect($outcome->state)
            ->toBe(Outcome::HIT)
            ->and($outcome->headers['Last-Modified'])->toBe('Thu, 01 Jan 1970 00:00:00 GMT');
    });
});
