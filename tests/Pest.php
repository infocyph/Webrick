<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case (Optional - only if needed)
|--------------------------------------------------------------------------
*/

// Most tests don't need a TestCase, but it's available if you want it
// uses(Tests\TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

expect()->extend('toHaveStatus', function (int $status) {
    $actual = $this->value->getStatusCode();
    expect($actual)->toBe($status, "Expected status {$status}, got {$actual}");
    return $this;
});

expect()->extend('toHaveHeader', function (string $header) {
    expect($this->value->hasHeader($header))->toBeTrue("Expected header '{$header}' to be present");
    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

// Helper functions available in all tests
function something(): void
{
    // Add helper functions here if needed
}

/*
|--------------------------------------------------------------------------
| Error Handler Cleanup (Integration & Feature Tests)
|--------------------------------------------------------------------------
| RouterKernel sets custom error handlers. We restore defaults after each test.
| This is EXPECTED behavior for a framework, not an error.
|--------------------------------------------------------------------------
*/

uses()->afterEach(function () {
    // Simply restore default error handlers - no complex logic needed
    @restore_error_handler();
    @restore_exception_handler();
})->in('Integration', 'Feature');
