<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use Infocyph\Webrick\Middleware\RiskAdmissionMiddleware;
use Infocyph\Webrick\Middleware\RiskDecision;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use RuntimeException;

/** An isolated, test-only host provider outage; not part of the Webrick public API. */
final class HostProofTransportUnavailable extends RuntimeException {}

/**
 * Test-only host composition: the host supplies verified provider responses
 * and a shared Redis domain. Webrick itself never knows provider credentials.
 */
final readonly class HostProofPolicyFixture
{
    /** @var Closure(string):mixed */
    private Closure $verify;

    /**
     * @param callable(string):mixed $verify
     * @throws \InvalidArgumentException
     */
    public function __construct(
        callable $verify,
        private Redis $redis,
        private string $keyPrefix,
        private string $secret,
    ) {
        $this->verify = Closure::fromCallable($verify);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function __invoke(Request $request): RiskDecision
    {
        if ($request->getAttribute('host.hard_deny') === true) {
            return RiskDecision::deny();
        }

        $body = $request->getAttribute('host.parsed_body');
        if (!is_array($body)) {
            return RiskDecision::challenge();
        }

        $proof = $body['captcha_token'] ?? null;
        if (!is_string($proof) || $proof === '' || strlen($proof) > 2048) {
            return RiskDecision::challenge();
        }

        try {
            $verification = ($this->verify)($proof);
        } catch (HostProofTransportUnavailable) {
            return RiskDecision::unavailable();
        }

        if (!is_array($verification)
            || ($verification['success'] ?? null) !== true
            || ($verification['hostname'] ?? null) !== 'login.example.test'
            || ($verification['action'] ?? null) !== 'authenticate'
        ) {
            return RiskDecision::challenge();
        }

        // Deliberately no tenant/account/route component: one provider proof
        // must not be claimable again through another accepting entry point.
        $key = $this->keyPrefix . hash_hmac('sha256', $proof, $this->secret);
        try {
            $claimed = $this->redis->set($key, 'consumed', ['nx', 'ex' => 330]);
        } catch (RedisException) {
            return RiskDecision::unavailable();
        }

        return $claimed === true ? RiskDecision::allow() : RiskDecision::challenge();
    }
}

final class RiskAdmissionHostProofBoundaryTest extends TestCase
{
    /**
     * @throws \Throwable
     */
    public function testHostProofResponseBindingAndCrossTenantReplayFailClosed(): void
    {
        $redis = self::redis();
        $prefix = 'webrick-test-proof-' . bin2hex(random_bytes(12)) . ':';
        $secret = random_bytes(32);
        $verified = 0;
        $handled = 0;
        $verify = static function (string $proof) use (&$verified): array {
            ++$verified;

            return match ($proof) {
                'wrong-hostname' => ['success' => true, 'hostname' => 'attacker.example.test', 'action' => 'authenticate'],
                'wrong-action' => ['success' => true, 'hostname' => 'login.example.test', 'action' => 'other'],
                'invalid-provider-proof' => ['success' => false, 'hostname' => 'login.example.test', 'action' => 'authenticate'],
                default => ['success' => true, 'hostname' => 'login.example.test', 'action' => 'authenticate'],
            };
        };

        $policy = new RiskAdmissionMiddleware(new HostProofPolicyFixture($verify, $redis, $prefix, $secret));
        $send = static function (
            string $proof,
            string $tenant,
            string $path,
            bool $hardDeny = false,
            bool $wrongPassword = false,
        ) use ($policy, &$handled): Response {
            $request = Request::fake(method: 'POST', uri: $path)
                ->withAttribute('host.parsed_body', ['captcha_token' => $proof])
                ->withAttribute('host.tenant', $tenant)
                ->withAttribute('host.hard_deny', $hardDeny);

            return $policy($request, static function () use (&$handled, $wrongPassword): Response {
                ++$handled;

                return Response::plaintext('host-auth', $wrongPassword ? 401 : 200);
            });
        };

        try {
            self::assertSame(200, $send('valid-provider-proof', 'tenant-a', '/auth/session')->getStatusCode());
            $duplicate = $send('valid-provider-proof', 'tenant-b', '/v3/identity/token');
            self::assertSame(429, $duplicate->getStatusCode());
            self::assertSame('no-store', $duplicate->getHeaderLine('Cache-Control'));

            self::assertSame(429, $send('wrong-hostname', 'tenant-a', '/auth/session')->getStatusCode());
            self::assertSame(429, $send('wrong-action', 'tenant-a', '/auth/session')->getStatusCode());
            self::assertSame(429, $send('invalid-provider-proof', 'tenant-a', '/auth/session')->getStatusCode());
            self::assertSame(403, $send('hard-denied', 'tenant-a', '/auth/session', hardDeny: true)->getStatusCode());

            self::assertSame(401, $send('valid-wrong-password', 'tenant-a', '/auth/session', wrongPassword: true)->getStatusCode());
            self::assertSame(429, $send('valid-wrong-password', 'tenant-a', '/auth/session')->getStatusCode());
            self::assertSame(2, $handled);
            self::assertSame(7, $verified);

            $spent = $prefix . hash_hmac('sha256', 'valid-provider-proof', $secret);
            $ttl = $redis->ttl($spent);
            self::assertGreaterThan(0, $ttl);
            self::assertLessThanOrEqual(330, $ttl);
            self::assertSame(0, $redis->exists($prefix . hash_hmac('sha256', 'wrong-hostname', $secret)));
        } finally {
            $redis->del(
                $prefix . hash_hmac('sha256', 'valid-provider-proof', $secret),
                $prefix . hash_hmac('sha256', 'valid-wrong-password', $secret),
            );
            $redis->close();
        }
    }

    /**
     * @throws \Throwable
     */
    public function testHostKnownProviderOutageCannotAuthorizeOrBurnProof(): void
    {
        $redis = self::redis();
        $prefix = 'webrick-test-proof-' . bin2hex(random_bytes(12)) . ':';
        $secret = random_bytes(32);
        $calls = 0;
        try {
            $policy = new RiskAdmissionMiddleware(
                new HostProofPolicyFixture(
                    static function (string $proof): array {
                        unset($proof);
                        throw new HostProofTransportUnavailable('simulated timeout');
                    },
                    $redis,
                    $prefix,
                    $secret,
                ),
            );
            $request = Request::fake(method: 'POST', uri: '/auth/session')
                ->withAttribute('host.parsed_body', ['captcha_token' => 'retryable-proof']);
            $response = $policy($request, static function () use (&$calls): Response {
                ++$calls;

                return Response::plaintext('unexpected', 200);
            });

            self::assertSame(503, $response->getStatusCode());
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
            self::assertSame(0, $calls);
            self::assertSame(0, $redis->exists($prefix . hash_hmac('sha256', 'retryable-proof', $secret)));
        } finally {
            $redis->close();
        }
    }

    /**
     * @throws \RuntimeException
     */
    private static function redis(): Redis
    {
        $host = getenv('IC_REDIS_HOST');
        $port = getenv('IC_REDIS_PORT');
        $client = new Redis();
        if (!$client->connect(
            is_string($host) && $host !== '' ? $host : '127.0.0.1',
            is_string($port) && ctype_digit($port) ? (int) $port : 6379,
            1.0,
        )) {
            throw new RuntimeException('Required isolated Redis integration service is unavailable.');
        }
        $password = getenv('IC_REDIS_PASSWORD');
        if (is_string($password) && $password !== '' && !$client->auth($password)) {
            throw new RuntimeException('Required Redis integration service denied authentication.');
        }

        return $client;
    }
}
