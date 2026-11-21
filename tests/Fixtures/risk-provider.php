<?php

declare(strict_types=1);

// Route discovery also includes fixtures. Only the explicitly selected HTTP
// router entrypoint may emit a provider response.
if (PHP_SAPI !== 'cli-server' || (get_included_files()[0] ?? null) !== __FILE__) {
    return;
}

// Local transport fault fixture; never a production CAPTCHA verifier.
/** @throws RuntimeException */
function providerFixturePath(mixed $uri): string
{
    if (!is_string($uri)) {
        throw new RuntimeException('Provider fixture URI must be a string.');
    }
    $path = parse_url($uri, PHP_URL_PATH);

    return is_string($path) ? $path : '/';
}
$path = providerFixturePath($_SERVER['REQUEST_URI'] ?? '/');
header('Content-Type: application/json');
if ($path === '/slow') {
    usleep(200_000);
}
if ($path === '/redirect') {
    http_response_code(302);
    header('Location: /valid');

    return;
}
if ($path === '/unavailable') {
    http_response_code(503);
}
if ($path === '/bad-request') {
    http_response_code(400);
}
$payload = match ($path) {
    '/malformed' => '{',
    '/oversized' => str_repeat('x', 4096),
    '/wrong-host' => '{"success":true,"hostname":"other.test","action":"authenticate"}',
    '/wrong-action' => '{"success":true,"hostname":"login.example.test","action":"other"}',
    '/invalid' => '{"success":false}',
    default => '{"success":true,"hostname":"login.example.test","action":"authenticate"}',
};
if (file_put_contents('php://output', $payload) === false) {
    throw new RuntimeException('Unable to emit provider fixture reply.');
}
