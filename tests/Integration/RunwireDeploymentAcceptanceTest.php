<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\RequestBodyInterface;
use Infocyph\Runwire\Http\ResponseWriterInterface;
use Infocyph\Runwire\Network\Enum\WriteState;
use Infocyph\Runwire\Network\WriteResult;
use Infocyph\Runwire\Runtime\Driver\FpmDriver;
use Infocyph\Runwire\Runtime\Driver\FrankenPhpDriver;
use Infocyph\Runwire\Runtime\Driver\RoadRunnerDriver;
use Infocyph\Runwire\Runtime\Driver\SwooleDriver;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\Host\HostDriverFactory;
use Infocyph\Runwire\Runtime\RuntimeEnvironment;
use Infocyph\Runwire\Runtime\RuntimeSelection;
use Infocyph\Runwire\Runtime\RuntimeSelector;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\RuntimeOptions;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeApplicationFactory;
use Infocyph\Webrick\Runtime\Http\SapiRuntimeAdapter;
use PHPUnit\Framework\TestCase;

final class DeploymentAcceptanceBody implements RequestBodyInterface
{
    public function bufferedBytes(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return true;
    }

    public function onData(callable $callback): RequestBodyInterface
    {
        unset($callback);

        return $this;
    }

    public function onEnd(callable $callback): RequestBodyInterface
    {
        Closure::fromCallable($callback)($this);

        return $this;
    }

    public function read(int $maxBytes = PHP_INT_MAX): string
    {
        unset($maxBytes);

        return '';
    }

    public function receivedBytes(): int
    {
        return 0;
    }

    public function trailers(): ?Headers
    {
        return new Headers();
    }
}

final class DeploymentAcceptanceWriter implements ResponseWriterInterface
{
    public int $endCalls = 0;

    public bool $ended = false;

    public bool $started = false;

    public function end(string $finalChunk = ''): WriteResult
    {
        ++$this->endCalls;
        $this->ended = true;

        return new WriteResult(WriteState::ACCEPTED, strlen($finalChunk));
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function onDrain(callable $callback): ResponseWriterInterface
    {
        unset($callback);

        return $this;
    }

    public function start(int $status = 200, ?Headers $headers = null): WriteResult
    {
        unset($status, $headers);
        $this->started = true;

        return new WriteResult(WriteState::ACCEPTED, 0);
    }

    public function write(string $chunk): WriteResult
    {
        return new WriteResult(WriteState::ACCEPTED, strlen($chunk));
    }
}

final class RunwireDeploymentAcceptanceTest extends TestCase
{
    public function testNativePortableAndPreforkSelectionsConsumeTheSameWebrickApplicationFactory(): void
    {
        $selector = new RuntimeSelector();
        $options = new RuntimeOptions(driver: RuntimeDriver::NATIVE);
        $portable = $selector->select(
            $options,
            new RuntimeEnvironment(
                sapi: 'cli',
                availableDrivers: [RuntimeDriver::NATIVE],
            ),
        );
        $prefork = $selector->select(
            $options,
            new RuntimeEnvironment(
                sapi: 'cli',
                availableDrivers: [RuntimeDriver::NATIVE],
                supportsFork: true,
                supportsSignals: true,
                supportsPosix: true,
            ),
        );

        self::assertSame(RuntimeDriver::NATIVE, $portable->driver);
        self::assertFalse($portable->capabilities->ownsWorkerPool);
        self::assertTrue($portable->capabilities->persistentApplication);
        self::assertNotEmpty($portable->warnings);
        self::assertSame(RuntimeDriver::NATIVE, $prefork->driver);
        self::assertTrue($prefork->capabilities->ownsWorkerPool);
        self::assertTrue($prefork->capabilities->persistentApplication);

        self::assertApplicationFactoryAcceptsSelection($portable, 'native-portable');
        self::assertApplicationFactoryAcceptsSelection($prefork, 'native-prefork');
    }

    public function testHostDriverSelectionsRemainRunwireOwnedAndAcceptTheWebrickFactory(): void
    {
        $selector = new RuntimeSelector();
        $factory = new HostDriverFactory();
        $cases = [
            [RuntimeDriver::FPM, 'fpm-fcgi', FpmDriver::class, false],
            [RuntimeDriver::FRANKENPHP, 'frankenphp', FrankenPhpDriver::class, true],
            [RuntimeDriver::ROADRUNNER, 'cli', RoadRunnerDriver::class, true],
            [RuntimeDriver::SWOOLE, 'cli', SwooleDriver::class, true],
        ];

        foreach ($cases as [$driver, $sapi, $expectedClass, $persistent]) {
            $options = new RuntimeOptions(driver: $driver);
            $environment = new RuntimeEnvironment(
                sapi: $sapi,
                hostedDrivers: [$driver],
                availableDrivers: [$driver],
                frankenPhpWorkerMode: $driver === RuntimeDriver::FRANKENPHP,
            );
            $selection = $selector->select($options, $environment);

            self::assertSame($driver, $selection->driver);
            self::assertSame($persistent, $selection->capabilities->persistentApplication);
            self::assertInstanceOf($expectedClass, $factory->create($driver, $options));
            self::assertApplicationFactoryAcceptsSelection($selection, 'host-' . $driver->value);
        }
    }

    public function testGenericSapiAdapterRemainsAnIndependentFirstClassPath(): void
    {
        $capabilities = SapiRuntimeAdapter::current()->capabilities();

        self::assertNotSame('runwire', $capabilities->name);
        self::assertFalse($capabilities->concurrent);
        self::assertFalse($capabilities->cancellationVisibility);
        self::assertFalse($capabilities->transportDrainVisibility);
    }

    private static function assertApplicationFactoryAcceptsSelection(RuntimeSelection $selection, string $mode): void
    {
        $seenDriver = null;
        $applicationFactory = new RunwireRuntimeApplicationFactory(
            handler: static function (HttpRequest $request, ResponseWriterInterface $writer): void {
                self::assertSame('/deployment', $request->target);
                $writer->start();
                $writer->end('ok');
            },
            lifecycle: new \Infocyph\Runwire\Runtime\ApplicationLifecycleHooks(
                boot: static function (RuntimeContext $context) use (&$seenDriver): void {
                    $seenDriver = $context->driver;
                },
            ),
        );
        $context = RuntimeContext::fromCapabilities($selection->capabilities, $mode);
        $application = $applicationFactory->create($context);
        $writer = new DeploymentAcceptanceWriter();

        $application->start();
        $application->handle(self::request(), $writer, completeResponse: true);
        $application->shutdown();

        self::assertSame($selection->driver, $seenDriver);
        self::assertTrue($writer->started);
        self::assertTrue($writer->ended);
        self::assertSame(1, $writer->endCalls);
        self::assertSame(1, $application->snapshot()->requestsTotal);
        self::assertSame(0, $application->snapshot()->requestsActive);
    }

    private static function request(): HttpRequest
    {
        return new HttpRequest(
            method: 'GET',
            target: '/deployment',
            version: ProtocolVersion::HTTP_1_1,
            headers: Headers::fromArray(['Host' => 'example.test']),
            body: new DeploymentAcceptanceBody(),
        );
    }
}
