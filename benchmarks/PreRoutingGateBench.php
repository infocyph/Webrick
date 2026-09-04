<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Benchmarks;

use Closure;
use Infocyph\Webrick\Middleware\Maintenance\MaintenanceModeMiddleware;
use Infocyph\Webrick\Middleware\Maintenance\MaintenancePreRoutingGate;
use Infocyph\Webrick\Middleware\Maintenance\MemoryMaintenanceState;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Runtime\RoutingInput;
use PhpBench\Attributes as Bench;
use RuntimeException;

#[Bench\Groups(['runtime', 'pre-routing-gate', 'maintenance'])]
#[Bench\Iterations(5)]
#[Bench\Revs(10_000)]
#[Bench\Warmup(2)]
final class PreRoutingGateBench
{
    private MaintenancePreRoutingGate $activeGate;

    private MaintenancePreRoutingGate $inactiveGate;

    private MaintenanceModeMiddleware $inactiveMiddleware;

    private Closure $next;

    private Request $request;

    private RoutingInput $routing;

    public function setUp(): void
    {
        $inactive = new MemoryMaintenanceState();
        $active = new MemoryMaintenanceState();
        $active->enable('Deploying');

        $this->routing = new RoutingInput('GET', '/bench');
        $this->request = Request::fake(uri: 'http://localhost/bench');
        $this->next = static fn(Request $request): Response => Response::plaintext('ok');
        $this->inactiveGate = new MaintenancePreRoutingGate(state: $inactive);
        $this->activeGate = new MaintenancePreRoutingGate(state: $active);
        $this->inactiveMiddleware = new MaintenanceModeMiddleware(state: $inactive);
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchActiveGate(): void
    {
        $response = $this->activeGate->evaluate($this->routing);
        if (!$response instanceof Response || $response->getStatusCode() !== 503) {
            throw new RuntimeException('Active pre-routing gate must return 503.');
        }
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchInactiveGate(): void
    {
        if ($this->inactiveGate->evaluate($this->routing) !== null) {
            throw new RuntimeException('Inactive pre-routing gate must pass.');
        }
    }

    #[Bench\BeforeMethods('setUp')]
    public function benchInactiveMaintenanceMiddleware(): void
    {
        $response = ($this->inactiveMiddleware)($this->request, $this->next);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Inactive maintenance middleware fixture returned an invalid response.');
        }
    }
}
