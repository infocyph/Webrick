<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Middleware;

use Closure;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Optional native middleware. The host owns risk evaluation and all security state.
 *
 * The evaluator must be observational when used in shadow mode: this middleware
 * does not redeem challenge tokens, modify trust state, or access the network.
 */
final readonly class RiskAdmissionMiddleware
{
    public const string ENFORCE = 'enforce';

    public const string SHADOW = 'shadow';

    /** @var Closure(Request):mixed */
    private Closure $evaluator;

    /** @var null|Closure(Request,RiskDecision):mixed */
    private ?Closure $renderer;

    /**
     * @param callable(Request):RiskDecision $evaluator
     * @param null|callable(Request,RiskDecision):Response $renderer
     */
    public function __construct(
        callable $evaluator,
        public string $mode = self::ENFORCE,
        ?callable $renderer = null,
    ) {
        if ($mode !== self::ENFORCE && $mode !== self::SHADOW) {
            throw new InvalidArgumentException('Risk admission mode must be enforce or shadow.');
        }

        $this->evaluator = Closure::fromCallable($evaluator);
        $this->renderer = $renderer === null ? null : Closure::fromCallable($renderer);
    }

    /** @param callable(Request):Response $next */
    public function __invoke(Request $request, callable $next): Response
    {
        $decision = ($this->evaluator)($request);
        if (!$decision instanceof RiskDecision) {
            throw new UnexpectedValueException('Risk admission evaluator must return RiskDecision.');
        }

        $request = $request->withAttribute(RiskDecision::ATTRIBUTE, $decision);
        if ($this->mode === self::SHADOW || $decision->action === RiskDecision::ALLOW) {
            return $next($request);
        }

        if ($this->renderer !== null) {
            $response = ($this->renderer)($request, $decision);
            if (!$response instanceof Response) {
                throw new UnexpectedValueException('Risk admission renderer must return Response.');
            }

            return $response->withHeader('Cache-Control', 'no-store');
        }

        [$status, $code] = match ($decision->action) {
            RiskDecision::CHALLENGE => [429, 'challenge_required'],
            RiskDecision::DENY => [403, 'access_denied'],
            RiskDecision::UNAVAILABLE => [503, 'temporarily_unavailable'],
            default => throw new UnexpectedValueException('Unknown risk admission decision.'),
        };

        return Response::json(['error' => $code], $status, ['Cache-Control' => 'no-store']);
    }
}
