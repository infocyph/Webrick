<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

final class RiskAdmissionRedisReplayTest extends TestCase
{
    /**
     * @throws \Throwable
     */
    public function testConcurrentIndependentWorkersConsumeOneProofOnlyOnce(): void
    {
        $redis = self::redis();
        $key = self::uniqueProofKey();
        $workers = [];

        try {
            for ($index = 0; $index < 8; ++$index) {
                $workers[] = self::worker($key, true, true);
            }
            foreach ($workers as $worker) {
                $worker->start();
            }

            $statuses = [];
            foreach ($workers as $worker) {
                $statuses[] = self::workerResult($worker);
            }

            self::assertCount(1, array_filter($statuses, static fn(array $item): bool => $item['status'] === 200));
            self::assertCount(7, array_filter($statuses, static fn(array $item): bool => $item['status'] === 429));
            self::assertSame(1, array_sum(array_column($statuses, 'handler_calls')));
            $remaining = $redis->ttl($key);
            self::assertGreaterThan(0, $remaining);
            self::assertLessThanOrEqual(300, $remaining);
        } finally {
            $redis->del($key);
            $redis->close();
        }
    }

    /**
     * @throws \Throwable
     */
    public function testIncorrectCredentialsAndUnverifiedProofCannotReuseOrBurnValidProof(): void
    {
        $redis = self::redis();
        $spent = self::uniqueProofKey();
        $fresh = self::uniqueProofKey();

        try {
            self::assertSame(['status' => 401, 'handler_calls' => 1],
                self::workerResult(self::worker($spent, true, false)));
            self::assertSame(['status' => 429, 'handler_calls' => 0],
                self::workerResult(self::worker($spent, true, true)));
            self::assertSame(['status' => 429, 'handler_calls' => 0],
                self::workerResult(self::worker($fresh, false, true)));
            self::assertFalse($redis->exists($fresh) !== 0);
            self::assertSame(['status' => 200, 'handler_calls' => 1],
                self::workerResult(self::worker($fresh, true, true)));
        } finally {
            $redis->del($spent, $fresh);
            $redis->close();
        }
    }

    /**
     * @throws \Throwable
     */
    public function testUnavailableSharedBackendFailsClosedBeforeHandler(): void
    {
        $result = self::workerResult(self::worker(self::uniqueProofKey(), true, true, 1));

        self::assertSame(['status' => 503, 'handler_calls' => 0], $result);
    }

    /**
     * @throws \RuntimeException
     */
    private static function redis(): \Redis
    {
        $client = new \Redis();
        if (!$client->connect(self::host(), self::port(), 1.0)) {
            throw new RuntimeException('Required isolated Redis integration service is unavailable.');
        }
        $password = getenv('IC_REDIS_PASSWORD');
        if (is_string($password) && $password !== '' && !$client->auth($password)) {
            throw new RuntimeException('Required Redis integration service denied authentication.');
        }

        return $client;
    }

    private static function host(): string
    {
        $host = getenv('IC_REDIS_HOST');

        return is_string($host) && $host !== '' ? $host : '127.0.0.1';
    }

    private static function port(): int
    {
        $port = getenv('IC_REDIS_PORT');

        return is_string($port) && ctype_digit($port) ? (int) $port : 6379;
    }

    private static function uniqueProofKey(): string
    {
        $token = random_bytes(32);

        return 'webrick-test-risk:' . bin2hex(random_bytes(8)) . ':' . hash('sha256', $token);
    }

    /**
     * @return array{status:int,handler_calls:int}
     * @throws \UnexpectedValueException
     * @throws \Throwable
     */
    private static function workerResult(Process $worker): array
    {
        if (!$worker->isStarted()) {
            $worker->run();
        } else {
            $worker->wait();
        }
        if (!$worker->isSuccessful()) {
            throw new UnexpectedValueException('Redis proof worker failed: ' . $worker->getErrorOutput());
        }
        $decoded = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new UnexpectedValueException('Redis proof worker returned malformed JSON evidence.');
        }

        $status = $decoded['status'] ?? null;
        $handlerCalls = $decoded['handler_calls'] ?? null;
        if (!is_int($status) || !is_int($handlerCalls)) {
            throw new UnexpectedValueException('Redis proof worker returned malformed JSON evidence.');
        }

        return ['status' => $status, 'handler_calls' => $handlerCalls];
    }

    /** @throws \Throwable */
    private static function worker(string $key, bool $proofValid, bool $credentialsValid, ?int $port = null): Process
    {
        return new Process(
            [
                PHP_BINARY,
                '-r',
                self::workerCode(),
                '--',
                self::host(),
                (string) ($port ?? self::port()),
                $key,
                $proofValid ? 'valid' : 'invalid',
                $credentialsValid ? 'valid' : 'incorrect',
            ],
            cwd: dirname(__DIR__, 2),
            timeout: 15,
        );
    }

    private static function workerCode(): string
    {
        return <<<'PHP'
require getcwd() . '/vendor/autoload.php';

$host = (string) $argv[1];
$port = (int) $argv[2];
$key = (string) $argv[3];
$proofValid = $argv[4] === 'valid';
$credentialsValid = $argv[5] === 'valid';

$middleware = new \Infocyph\Webrick\Middleware\RiskAdmissionMiddleware(
    static function (\Infocyph\Webrick\Request\Request $request) use ($host, $port, $key): \Infocyph\Webrick\Middleware\RiskDecision {
        if ($request->getAttribute('host.proof_validated') !== true) {
            return \Infocyph\Webrick\Middleware\RiskDecision::challenge();
        }
        try {
            $redis = new \Redis();
            $redis->connect($host, $port, 0.5);
            $password = getenv('IC_REDIS_PASSWORD');
            if (is_string($password) && $password !== '' && !$redis->auth($password)) {
                return \Infocyph\Webrick\Middleware\RiskDecision::unavailable();
            }
            $claimed = $redis->set($key, 'claimed', ['nx', 'ex' => 300]);
            $redis->close();
            return $claimed === true
                ? \Infocyph\Webrick\Middleware\RiskDecision::allow()
                : \Infocyph\Webrick\Middleware\RiskDecision::challenge();
        } catch (\RedisException) {
            return \Infocyph\Webrick\Middleware\RiskDecision::unavailable();
        }
    },
);

$called = 0;
$request = \Infocyph\Webrick\Request\Request::fake(method: 'POST', uri: '/host/login')
    ->withAttribute('host.proof_validated', $proofValid);
$response = $middleware($request, static function () use (&$called, $credentialsValid): \Infocyph\Webrick\Response\Response {
    ++$called;
    return \Infocyph\Webrick\Response\Response::plaintext('host-auth', $credentialsValid ? 200 : 401);
});

echo json_encode(['status' => $response->getStatusCode(), 'handler_calls' => $called], JSON_THROW_ON_ERROR);
PHP;
    }
}
