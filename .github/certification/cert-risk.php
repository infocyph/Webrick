<?php

declare(strict_types=1);

use Infocyph\Webrick\Middleware\RiskAdmissionMiddleware;
use Infocyph\Webrick\Middleware\RiskDecision;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

// Test-only host composition. These routes and credentials are never packaged.
// Baseline and candidate execute identical host policy and authentication work.
const CERT_RISK_PASSWORD_HASH = '$2y$10$5Ww1TxgY9ofQbcJ9D7u8QenFT3VE2JzVWuwiKMc.B.F5UJp/rerxy';
const CERT_RISK_COUNTER_SCRIPT = <<<'LUA'
local count = redis.call('INCR', KEYS[1])
if count == 1 or redis.call('TTL', KEYS[1]) < 0 then
    redis.call('EXPIRE', KEYS[1], 60)
end
return count
LUA;

/** @throws RuntimeException */
function certification_risk_mode(): string
{
    $mode = getenv('WEBRICK_CERT_ADMISSION');
    if ($mode !== 'native' && $mode !== 'middleware') {
        throw new RuntimeException('Protected certification requires explicit native or middleware admission.');
    }
    if ($mode === 'middleware' && !class_exists(RiskAdmissionMiddleware::class)) {
        throw new RuntimeException('Candidate risk admission middleware is unavailable.');
    }

    return $mode;
}

/**
 * The certification operator supplies an isolated Redis instance. No fallback.
 * @throws RuntimeException
 * @throws RedisException
 */
function certification_risk_store(): Redis
{
    static $redis = null;
    if ($redis instanceof Redis) {
        return $redis;
    }
    $host = getenv('IC_REDIS_HOST');
    $port = getenv('IC_REDIS_PORT');
    if ($host !== '127.0.0.1' || $port === false || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('Protected certification requires an isolated loopback Redis port.');
    }
    $connection = new Redis();
    if (!$connection->connect($host, (int) $port, 0.5, read_timeout: 0.5)) {
        throw new RuntimeException('Protected certification Redis connection failed.');
    }
    $redis = $connection;

    return $redis;
}

/**
 * Bounded synthetic subjects; no client identifiers become Redis keys.
 * @throws RuntimeException
 * @throws RedisException
 */
function certification_risk_prepare(): void
{
    certification_risk_mode();
    $redis = certification_risk_store();
    if ($redis->set('webrick-cert:risk:account', CERT_RISK_PASSWORD_HASH, ['ex' => 7200]) !== true) {
        throw new RuntimeException('Unable to seed the certification account.');
    }
}

/**
 * The slow-provider case is a bounded local latency fixture, not live CAPTCHA evidence.
 * @throws RuntimeException
 * @throws RedisException
 * @throws InvalidArgumentException
 */
function certification_risk_action(Request $request, string $workload): string
{
    $forced = $request->getHeaderLine('X-Cert-Risk-Action');
    if (in_array($forced, ['challenge', 'deny', 'unavailable'], true)) {
        return $forced;
    }
    if ($workload === 'risk_allow') {
        return 'allow';
    }
    if ($workload === 'risk_provider_slow') {
        usleep(5_000);
    }
    $redis = certification_risk_store();
    if ($workload === 'risk_clearance') {
        // Model a pre-existing outstanding clearance, then atomically consume it.
        // Each request owns a random fixture key; it expires even if the worker dies.
        $key = 'webrick-cert:risk:clearance:' . bin2hex(random_bytes(16));
        if ($redis->set($key, 'outstanding', ['nx', 'ex' => 60]) !== true) {
            return 'unavailable';
        }

        return $redis->getDel($key) === 'outstanding' ? 'allow' : 'challenge';
    }
    $count = $redis->eval(CERT_RISK_COUNTER_SCRIPT, ['webrick-cert:risk:attempts'], 1);

    return is_int($count) && $count > 0 ? 'allow' : 'unavailable';
}

/**
 * @throws RuntimeException
 * @throws InvalidArgumentException
 */
function certification_risk_evaluate(Request $request, string $workload): string
{
    try {
        return certification_risk_action($request, $workload);
    } catch (RedisException) {
        return 'unavailable';
    }
}

/**
 * Ordinary baseline middleware and candidate integration share this exact evaluator.
 * @param callable(Request):Response $next
 * @throws Throwable
 */
function certification_risk_admit(Request $request, callable $next, string $workload): Response
{
    if (str_starts_with($workload, 'risk_login') || $workload === 'risk_provider_slow') {
        $body = json_decode((string) $request->getBody(), true, 8, JSON_THROW_ON_ERROR);
        $request = $request->withAttribute('cert.parsed_login', $body);
    }
    if (certification_risk_mode() === 'middleware') {
        $admission = new RiskAdmissionMiddleware(
            static fn(Request $current): RiskDecision => match (certification_risk_evaluate($current, $workload)) {
                'allow' => RiskDecision::allow(),
                'challenge' => RiskDecision::challenge(),
                'deny' => RiskDecision::deny(),
                default => RiskDecision::unavailable(),
            },
        );

        return $admission($request, $next);
    }
    $action = certification_risk_evaluate($request, $workload);
    if ($action === 'allow') {
        return $next($request);
    }
    [$status, $code] = match ($action) {
        'challenge' => [429, 'challenge_required'],
        'deny' => [403, 'access_denied'],
        default => [503, 'temporarily_unavailable'],
    };

    return Response::json(['error' => $code], $status, ['Cache-Control' => 'no-store']);
}

/**
 * Real shared account lookup and bcrypt verification, including wrong passwords.
 * @throws Throwable
 */
function certification_risk_login(Request $request): Response
{
    $body = $request->getAttribute('cert.parsed_login');
    if (!is_array($body) || ($body['account'] ?? null) !== 'cert-account' || !is_string($body['password'] ?? null)) {
        return Response::json(['error' => 'invalid_credentials'], 401, ['Cache-Control' => 'no-store']);
    }
    $hash = certification_risk_store()->get('webrick-cert:risk:account');
    if (!is_string($hash)) {
        return Response::json(['error' => 'temporarily_unavailable'], 503, ['Cache-Control' => 'no-store']);
    }
    $valid = password_verify($body['password'], $hash);

    return Response::json(
        $valid ? ['authenticated' => true] : ['error' => 'invalid_credentials'],
        $valid ? 200 : 401,
        ['Cache-Control' => 'no-store'],
    );
}
