Risk admission
==============

``RiskAdmissionMiddleware`` is an **optional route middleware**. An application
explicitly selects protected routes and supplies the evaluator and, optionally,
a rejection renderer. Webrick does not own authentication, scoring, CAPTCHA,
proxy trust, provider I/O, security state, session management or credentials.

Native registrar example
------------------------

The host chooses the path, evaluator and handler; no new global route, PHP
session or additional Webrick provider is installed:

.. code-block:: php

   use Infocyph\Webrick\Middleware\RiskAdmissionMiddleware;
   use Infocyph\Webrick\Middleware\RiskDecision;
   use Infocyph\Webrick\Request\Request;
   use Infocyph\Webrick\Response\Response;
   use Infocyph\Webrick\Router\Definition\Registrar;

   $loginPath = '/auth/session'; // Host-selected setting
   $evaluator = static function (Request $request): RiskDecision {
       // Demonstration only, replace with the host's configured policy.
       return RiskDecision::challenge(reasonCodes: ['proof_required']);
   };
   $login = static fn(): Response => Response::json(['ok' => true]);

   $register = static function (Registrar $routes) use ($loginPath, $evaluator, $login): void {
       $routes->get('/health', static fn(): Response => Response::plaintext('ok'));
       $routes->post($loginPath, $login, [
           'middleware' => [new RiskAdmissionMiddleware($evaluator)],
       ]);
   };

Pass ``$register`` to ``RouterKernel::bootWithRegistrar`` along with the
application's InterMix invoker and matcher (see :doc:`../index`). The
unprotected health route invokes no policy. A request to the selected
protected route gets HTTP 429 and JSON ``{"error":"challenge_required"}``,
with ``Cache-Control: no-store``; the protected handler is never called.
This is a **routing demonstration**, not a production password or provider
implementation.

Decisions, shadow and errors
----------------------------

An evaluator returns one immutable ``RiskDecision``: ``allow`` (continue
once), ``challenge`` (429), ``deny`` (403) or ``unavailable`` (503).
The default rejecting response includes only a stable error code and
``Cache-Control: no-store``; bounded, non-secret score/reason metadata is
attached to the current immutable Request using ``RiskDecision::ATTRIBUTE``.
An optional host renderer receives the request and decision and must
return a native Response. Webrick forces no-store on its rejecting response.

``RiskAdmissionMiddleware::ENFORCE`` is the default. The explicit
``RiskAdmissionMiddleware::SHADOW`` mode forwards once for every valid
decision but does not implement any safeguards around an impure host
evaluator. The host **must** use an observational evaluator that does not
redeem provider tokens, claim replay records, consume clearance, change
session/trust state, or write auth counters merely to observe a verdict.

Malformed evaluator, renderer or mode inputs raise contract exceptions.
Known dependency outages should be converted by the host to
``RiskDecision::unavailable()``; Webrick does not catch arbitrary failures
and turn them into admission. Standard error handling and HEAD response
normalization remain owned by the kernel. Do not expose reasons, scores,
credential content, provider responses or exception details to clients.

Stateless REST proof flow (host responsibility)
----------------------------------------------

1. The host enforces body/request limits and validates credentials and proof
   fields once before expensive password verification. A challenged request
   without valid proof gets the ordinary 429. Webrick does **not** parse the
   body a second time.
2. The frontend obtains a provider proof and retries the *same*
   host-selected HTTPS protected route with ``captcha_token`` in its JSON
   request body. Never put proof in URLs or logs.
3. The host client uses a configured, approved HTTPS verification endpoint
   with TLS verification, explicit connect/total timeouts, bounded response
   size and supplied cancellation. Validate success, expected hostname,
   expected action, proof schema and account/tenant binding. HTTP requests
   cannot choose or redirect the provider endpoint.
4. After a valid verification response, atomically claim a cryptographic
   digest of the proof in a shared authoritative anti-replay store, across
   all accepting routes/accounts/tenants that share the provider proof
   domain. Concurrent provider validations may both succeed; **only one
   successful atomic claimant** may reach the auth handler.
5. Retain consumed proof state throughout the provider acceptance interval
   plus a clock margin. Wrong-password and ambiguous-response attempts
   still consume their proof. Do not allow a later retry of the same proof.
6. Hard denies, independent rate limits, authentication capacity and
   credential verification remain enforced after CAPTCHA admission. The
   proof is not an authentication credential.

If the host opts for a separate exchange endpoint, the host chooses its URL,
deduplicates the proof before issuing a short-lived opaque clearance and
atomically consumes positive outstanding state once. A signed token alone
does not guarantee single use. No Webrick route is auto-created.

Security state, proxy and worker boundaries
-------------------------------------------

Use a shared storage backend with demonstrated atomic operations and
known durability, eviction, failover and expiry guarantees. CacheLayer's
provider-specific atomic API can support one-use state when its configured
provider/topology is qualified; generic get/save is not atomic. A local cache,
PHP process memory, Runwire-local semaphore or expiring shared counter is
**not** a substitute for cross-process redemption correctness. Missing
atomic capabilities and backend errors must fail closed.

JA4 is a risk signal, not a device identifier. Only use proxy-derived
client IP/JA4 through a configured and authenticated edge boundary that
overwrites client-provided headers. Trust-cookie benefits must bind account,
tenant, purpose and expiry, support revocation, and never appear in JSON.
Configure host CORS, body limits, admission budgets and response-cache
exclusions for authentication routes.

The host owns InterMix request-scoped policy dependencies. Do not capture a
Request, account, tenant, mutable identity or cancellation token in a
worker-global callback. Ordinary SAPI requires no Runwire runtime; a host
choosing Runwire must pass its existing cancellation/deadline context.

Verification and release limitations
------------------------------------

Unit and registrar tests establish local middleware decisions, route
selection, shadow forwarding and handler bypass. They do **not** prove
provider security or cross-process atomicity. The contract's
``A14–A26`` tests require a real isolated shared backend and adversarial
provider/client fixtures: concurrent redemption, outage, restart/eviction,
expiry, malformed proof/response, cancellations, account binding and
host-configured paths/URLs. Do not count in-process doubles as distributed
evidence.

Production builds can serialize *pure* closure middleware fixtures into
executable router artifacts, but never use that capability to embed
credential-bearing clients, mutable provider objects or request-specific state.
Resolve real policy services through the host's InterMix runtime and keep
deployments and source-free consumers qualified separately. The compiled
parity regression exercises the pure-fixture case only.

Before claiming release readiness also verify compiled/source-free and
no-dev consumers, optional-provider absence, strict Sphinx HTML docs,
archive contents, full PHPForge detail checks, matched authorized-request
performance (at most 2% RPM, 15% p99, 32 MiB RSS regression) and the
required 30-minute persistent-worker soak. The maintained acceptance
ledger stays open until exact-revision evidence exists. A green
generic Security & Standards workflow alone does not cover these checks.
