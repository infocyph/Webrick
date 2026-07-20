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
