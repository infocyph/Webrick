<?php

declare(strict_types=1);

namespace Tests\Integration;

use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Http\Enum\ProtocolVersion;
use Infocyph\Runwire\Http\Headers;
use Infocyph\Runwire\Http\HttpRequest;
use Infocyph\Runwire\Http\Internal\BufferedRequestBody;
use Infocyph\Runwire\Http\Internal\CallbackResponseWriter;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Webrick\Request\Core\ServerRequest;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Runtime\Http\RunwireRuntimeAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use UnexpectedValueException;

final class RunwireEarlyFormRejectionTest extends TestCase
{
    /** @throws \Throwable */
    public function testCancelledRoutingFormReadNeverConsumesBodyOrStartsResponse(): void
    {
        $configuration = new ReflectionProperty(ServerRequest::class, 'methodParamOverride');
        $previous = Request::getMethodParamOverride();
        $configuration->setValue(null, true);

        try {
            foreach ([CancellationReason::TRANSPORT_CANCELLED, CancellationReason::DEADLINE_EXCEEDED] as $reason) {
                $body = new BufferedRequestBody('_method=PUT');
                $request = new HttpRequest(
                    'POST',
                    '/forms',
                    ProtocolVersion::HTTP_1_1,
                    Headers::fromArray(['Content-Type' => 'application/x-www-form-urlencoded']),
                    $body,
                );
                $writer = new CallbackResponseWriter(
                    static function (int $status, Headers $headers): void {
                        unset($status, $headers);
                    },
                    static function (string $chunk): void {
                        unset($chunk);
                    },
                    static function (): void {},
                    maxBodyBytes: 4096,
                );
                $request->context->cancel($reason);

                try {
                    new RunwireRuntimeAdapter(maxRoutingFormBytes: 64)->context($request, $writer);
                    self::fail('Cancelled routing form unexpectedly created a runtime context.');
                } catch (CancelledException $exception) {
                    self::assertSame($reason, $exception->reason);
                }

                self::assertSame(strlen('_method=PUT'), $body->bufferedBytes());
                self::assertFalse($writer->isStarted());
                self::assertFalse($writer->isEnded());
            }
        } finally {
            $configuration->setValue(null, $previous);
        }
    }

    /** @throws \Throwable */
    public function testFormOverrideRejectsOversizeBeforeRoutingAndAcceptsBoundedForms(): void
    {
        $configuration = new ReflectionProperty(ServerRequest::class, 'methodParamOverride');
        $previous = Request::getMethodParamOverride();
        $configuration->setValue(null, true);

        try {
            $body = new BufferedRequestBody('_method=PUT&name=abc');
            $request = new HttpRequest(
                'POST',
                '/forms',
                ProtocolVersion::HTTP_1_1,
                Headers::fromArray(['Content-Type' => 'application/x-www-form-urlencoded']),
                $body,
            );
            $status = null;
            $header = null;
            $parts = [];
            $writer = new CallbackResponseWriter(
                static function (int $code, Headers $headers) use (&$status, &$header): void {
                    $status = $code;
                    $header = $headers->first('content-type');
                },
                static function (string $chunk) use (&$parts): void {
                    $parts[] = $chunk;
                },
                static function (): void {},
                maxBodyBytes: 4096,
            );

            $adapter = new RunwireRuntimeAdapter(maxRoutingFormBytes: 8);
            $rejected = $adapter->context($request, $writer);
            $rejection = $rejected->preRoutingResponse;
            if (!$rejection instanceof Response) {
                throw new UnexpectedValueException('Oversized routing form was not rejected.');
            }
            $adapter->write($rejection, $rejected);

            self::assertSame('POST', $rejected->routing->method);
            self::assertSame(413, $rejection->getStatusCode());
            self::assertSame(413, $status);
            self::assertStringContainsString('application/json', (string) $header);
            self::assertTrue($writer->isEnded());
            self::assertStringContainsString('payload_too_large', implode('', $parts));
            self::assertSame(strlen('_method=PUT&name=abc'), $body->bufferedBytes());

            $acceptedBody = new BufferedRequestBody('_method=PUT');
            $acceptedRequest = new HttpRequest(
                'POST',
                '/forms',
                ProtocolVersion::HTTP_1_1,
                Headers::fromArray(['Content-Type' => 'application/x-www-form-urlencoded']),
                $acceptedBody,
            );
            $unusedWriter = new CallbackResponseWriter(
                static function (int $code, Headers $headers): void {
                    unset($code, $headers);
                },
                static function (string $chunk): void {
                    unset($chunk);
                },
                static function (): void {},
                maxBodyBytes: 4096,
            );
            $accepted = new RunwireRuntimeAdapter(maxRoutingFormBytes: 64)
                ->context($acceptedRequest, $unusedWriter);

            self::assertNull($accepted->preRoutingResponse);
            self::assertSame('PUT', $accepted->routing->method);
            // The wire method stays POST; routing observes the effective PUT override.
            self::assertSame('POST', $accepted->request()->getMethod());
            self::assertSame('PUT', $accepted->request()->getEffectiveMethod());
            self::assertSame('PUT', $accepted->request()->post('_method'));
        } finally {
            $configuration->setValue(null, $previous);
        }
    }
}
