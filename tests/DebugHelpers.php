<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Test debugging helpers
 */
function debugOut(string $message): void
{
    file_put_contents('php://stdout', $message, FILE_APPEND);
}

if (! function_exists('debugResponse')) {
    function debugResponse(Response $response): void
    {
        debugOut("\n");
        debugOut("================== RESPONSE DEBUG ==================\n");
        debugOut('Status: '.$response->getStatusCode().' '.$response->getReasonPhrase()."\n");
        debugOut("\nHeaders:\n");
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                debugOut("  {$name}: {$value}\n");
            }
        }
        debugOut("\nBody:\n");
        debugOut(substr((string) $response->getBody(), 0, 500)."\n");
        debugOut("====================================================\n\n");
    }
}

if (! function_exists('debugRequest')) {
    function debugRequest(Request $request): void
    {
        debugOut("\n");
        debugOut("================== REQUEST DEBUG ===================\n");
        debugOut('Method: '.$request->getMethod()."\n");
        debugOut('URI: '.$request->getUri()."\n");
        debugOut("\nHeaders:\n");
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                debugOut("  {$name}: {$value}\n");
            }
        }
        debugOut("\nServer:\n");
        foreach ($request->getServerParams() as $key => $val) {
            if (is_string($val)) {
                debugOut("  {$key}: {$val}\n");
            }
        }
        debugOut("====================================================\n\n");
    }
}
