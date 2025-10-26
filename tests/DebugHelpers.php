<?php

/**
 * Test debugging helpers
 */

if (!function_exists('debugResponse')) {
    function debugResponse(\Infocyph\Webrick\Response\Response $response): void
    {
        echo "\n";
        echo "================== RESPONSE DEBUG ==================\n";
        echo "Status: " . $response->getStatusCode() . " " . $response->getReasonPhrase() . "\n";
        echo "\nHeaders:\n";
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                echo "  {$name}: {$value}\n";
            }
        }
        echo "\nBody:\n";
        echo substr((string)$response->getBody(), 0, 500) . "\n";
        echo "====================================================\n\n";
    }
}

if (!function_exists('debugRequest')) {
    function debugRequest(\Infocyph\Webrick\Request\Request $request): void
    {
        echo "\n";
        echo "================== REQUEST DEBUG ===================\n";
        echo "Method: " . $request->getMethod() . "\n";
        echo "URI: " . $request->getUri() . "\n";
        echo "\nHeaders:\n";
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                echo "  {$name}: {$value}\n";
            }
        }
        echo "\nServer:\n";
        foreach ($request->getServerParams() as $key => $val) {
            if (is_string($val)) {
                echo "  {$key}: {$val}\n";
            }
        }
        echo "====================================================\n\n";
    }
}
