<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\RiskAdmissionMiddleware;
use Infocyph\Webrick\Middleware\RiskDecision;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Symfony\Component\Process\Process;

/**
 * Test-only host HTTP verifier. Endpoint is supplied by the test host, never a request.
 * This exercises real transport bounds, not a live CAPTCHA provider or clearance issuer.
 * @throws Throwable
 */
function localRiskProviderDecision(string $endpoint): RiskDecision
{
    $client = curl_init($endpoint);
    if ($client === false) {
        throw new RuntimeException('Unable to create local provider test client.');
    }
    $body = '';
    curl_setopt_array($client, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'response=fixture-proof',
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT_MS => 100,
        CURLOPT_TIMEOUT_MS => 100,
        CURLOPT_PROXY => '',
        CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (&$body): int {
            if (curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200 || strlen($body) + strlen($chunk) > 1024) {
                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        },
    ]);
    $sent = curl_exec($client);
    $status = curl_getinfo($client, CURLINFO_RESPONSE_CODE);
    if ($sent === false || $status !== 200) {
        return RiskDecision::unavailable();
    }

    try {
        $verification = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return RiskDecision::unavailable();
    }
    if (!is_array($verification)
        || ($verification['success'] ?? null) !== true
        || ($verification['hostname'] ?? null) !== 'login.example.test'
        || ($verification['action'] ?? null) !== 'authenticate'
    ) {
        return RiskDecision::challenge();
    }

    return RiskDecision::allow();
}

/**
 * @return array{Process,string}
 * @throws Throwable
 */
function localRiskProviderServer(): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Unable to reserve provider fixture port: ' . $error);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address)) {
        throw new RuntimeException('Provider fixture has no local address.');
    }
    $process = new Process([PHP_BINARY, '-S', $address, dirname(__DIR__) . '/Fixtures/risk-provider.php'], timeout: 5);
    $process->start();
    if ($process->waitUntil(static fn(string $type, string $output): bool => $type === Process::ERR && str_contains($output, 'Development Server'))) {
        return [$process, 'http://' . $address];
    }
    $process->stop();

    throw new RuntimeException('Provider HTTP fixture did not start.');
}

test('host HTTP provider bounds and reply validation fail closed before authentication', function (string $path, int $expected): void {
    [$server, $endpoint] = localRiskProviderServer();
    $attempts = 0;
    $handled = 0;

    try {
        $middleware = new RiskAdmissionMiddleware(static function () use ($endpoint, $path, &$attempts): RiskDecision {
            ++$attempts;

            return localRiskProviderDecision($endpoint . $path);
        });
        $response = $middleware(Request::fake(method: 'POST', uri: '/host-selected/login'), static function () use (&$handled): Response {
            ++$handled;

            return Response::plaintext('authenticated', 200);
        });

        expect($response->getStatusCode())->toBe($expected)
            ->and($attempts)->toBe(1)
            ->and($handled)->toBe($expected === 200 ? 1 : 0);
        if ($expected !== 200) {
            expect($response->getHeaderLine('Cache-Control'))->toBe('no-store')
                ->and((string) $response->getBody())->not->toContain('fixture-proof', $endpoint);
        }
    } finally {
        $server->stop();
    }
})->with([
    ['/valid', 200],
    ['/invalid', 429],
    ['/wrong-host', 429],
    ['/wrong-action', 429],
    ['/malformed', 503],
    ['/oversized', 503],
    ['/unavailable', 503],
    ['/bad-request', 503],
    ['/redirect', 503],
    ['/slow', 503],
]);
