<?php
declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Request\Request;

/**
 * Injects **Network Error Logging** (NEL) + **Report-To** headers.
 *
 * Configure once at bootstrap; clients will send error reports
 * asynchronously to the specified endpoint.
 */
final readonly class NelMiddleware
{
    public function __construct(
        private string $reportGroup,
        private string $reportEndpoint,           // absolute URL
        private int    $ttlSeconds   = 86400,     // max-age
        private bool   $includeSub   = true,
        private bool   $successFrac  = false,     // collect successes?
    ) {}

    public function __invoke(Request $req, Closure $next): Response
    {
        $policy = [
            'group'              => $this->reportGroup,
            'max_age'            => $this->ttlSeconds,
            'include_subdomains' => $this->includeSub,
            'success_fraction'   => $this->successFrac ? 1.0 : 0.0,
            'failure_fraction'   => 1.0,
        ];

        $reportTo = [
            'group' => $this->reportGroup,
            'max_age' => $this->ttlSeconds,
            'endpoints' => [['url' => $this->reportEndpoint]],
        ];

        $resp = $next($req);
        return $resp
            ->withHeader('NEL', json_encode($policy, JSON_THROW_ON_ERROR))
            ->withHeader('Report-To', json_encode($reportTo, JSON_THROW_ON_ERROR));
    }
}
