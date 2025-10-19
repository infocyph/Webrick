<?php

/**
 * Fix integration test issues
 */

$routingTest = 'tests/Integration/RoutingTest.php';
$content = file_get_contents($routingTest);

// Add proper server vars to mockRequest calls
$content = preg_replace(
    '/mockRequest\((\'[A-Z]+\'), (\'[^\']+\')\)/',
    "mockRequest($1, $2, [], null, [], ['HTTP_HOST' => 'localhost', 'SERVER_NAME' => 'localhost'])"
);

file_put_contents($routingTest, $content);

echo "✅ Fixed: {$routingTest}\n";

// Fix middleware pipeline test
$middlewareTest = 'tests/Integration/MiddlewarePipelineTest.php';
$content = file_get_contents($middlewareTest);

// Make sure REQUEST_TIME is set
$content = str_replace(
    "describe('Middleware Pipeline', function () {",
    "describe('Middleware Pipeline', function () {
    beforeEach(function () {
        \$_SERVER['REQUEST_TIME'] = time();
        \$_SERVER['HTTP_HOST'] = 'localhost';
    });"
);

file_put_contents($middlewareTest, $content);

echo "✅ Fixed: {$middlewareTest}\n";

echo "\nRun: composer test\n";
