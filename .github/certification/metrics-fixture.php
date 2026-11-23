<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || (get_included_files()[0] ?? null) !== __FILE__) {
    return;
}

header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    file_put_contents('php://stderr', "METRICS_FIXTURE_WORK_STARTED\n");
    // A valid request can outlast the ordinary two-second control probe.
    usleep(3_000_000);
    $payload = '{"ok":true}';
} elseif (getenv('WEBRICK_METRICS_FIXTURE_MODE') === 'malformed') {
    $payload = '{}';
} else {
    $payload = json_encode([
        'pid' => 1,
        'memory_current_bytes' => 1024,
        'memory_peak_bytes' => 1024,
        'requests_active' => getenv('WEBRICK_METRICS_FIXTURE_MODE') === 'leaked' ? 2 : 0,
        'queued_bytes_current' => 0,
        'deferred_backlog' => 0,
        'rejected_requests_total' => 0,
    ], JSON_THROW_ON_ERROR);
}
if (file_put_contents('php://output', $payload) === false) {
    throw new RuntimeException('Unable to emit metrics fixture reply.');
}
