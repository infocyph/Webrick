# Webrick 5 — Runwire 1.0 Runtime Adapter, InterMix 10.1 Scope & Persistent HTTP Plan

## Status

Target: **Webrick 5 + Runwire 1.0 + InterMix 10.1**

Branch: `webrick-5/runwire-runtime-adapter`

Primary consumer: **Foundation 3**

Primary end-to-end acceptance application: **Infbyte**

Released lower/runtime baselines:

- Runwire **1.0**, tag commit `7ab48fcf224ee86838e5bf82a50b998c2aaa8a90`;
- InterMix **10.1**, tag commit `16291f15e3dabdf6063557b7ac336e5e301ee5f1`;
- Webrick branch baseline before this plan revision: `0e6a61f98ddb32f8a5537c702afdef610b6c9abf`.

Priority:

> HTTP correctness → deployment portability → request/execution isolation → bounded streaming/backpressure → cancellation/lifecycle correctness → security boundaries → low-copy adaptation → performance → ergonomics

This is the single canonical Webrick 5 Runwire integration plan. It supersedes assumptions made while Runwire 1.0 and InterMix 10.1 were still under design.

Runwire 1.0 shipped materially more runtime substrate than the original plan assumed: HTTP/1.1, HTTP/2, HTTP/3, normalized `HttpRequest`, streaming response writers, request context/cancellation/deadlines, generic application lifecycle, admission control, worker recycling, host-runtime drivers and structured concurrency. InterMix 10.1 subsequently shipped explicit logical `ScopeContext` capture/attach semantics for structured child work.

Webrick 5 must consume those released contracts instead of rebuilding parallel runtime machinery.

---

# 1. Release objective

Webrick 5 must provide one clean application-facing HTTP model across:

```text
ordinary request-owned PHP
    Apache / LiteSpeed / CGI / FastCGI / PHP-FPM / shared hosting

persistent host runtimes
    FrankenPHP / RoadRunner / Swoole / OpenSwoole

Runwire native
    native prefork
    native portable without PCNTL/POSIX
    HTTP/1.1 / HTTP/2 / HTTP/3 where capabilities exist
```

The same Webrick route, middleware, request semantics and response semantics must remain valid across these environments.

Runwire expands Webrick/Foundation deployment capability. It must **not** become a prerequisite for running Webrick on ordinary PHP hosting.

---

# 2. Hard portability invariant

The lowest-common-denominator Webrick environment remains ordinary request-scoped PHP.

Webrick 5 must remain usable with:

```text
PHP 8.4+
Apache/mod_php
LiteSpeed
CGI
FastCGI
PHP-FPM
shared hosting
serverless/request-owned PHP
plain CLI/test execution
```

The Webrick production contract must not require:

- Runwire;
- 64-bit PHP solely because Runwire requires it;
- PCNTL;
- POSIX;
- process signals;
- a persistent worker;
- a long-running server;
- Swoole/OpenSwoole;
- an event loop;
- Fiber scheduling;
- QUIC;
- HTTP/2 or HTTP/3 support from the host.

When Runwire is explicitly installed, Composer may enforce Runwire's own `php-64bit` platform requirement. That must not leak into Webrick's base package requirement.

Advanced capabilities degrade to ordinary synchronous/request-owned behavior when absent. Application correctness must never depend on preforking, structured concurrency or persistence.

---

# 3. Ownership boundary

## 3.1 Runwire owns

Runwire 1.0 is authoritative for generic lower-runtime mechanics:

- master/worker process supervision;
- native prefork and portable single-process server modes;
- PCNTL/POSIX mechanics when available;
- event-loop ownership;
- TCP/UDP/Unix/TLS listeners;
- connection lifecycle;
- socket buffers/backpressure;
- HTTP/1.1 wire parsing/framing/serialization;
- HTTP/2 frame/stream/HPACK/flow-control mechanics;
- HTTP/3/QUIC/QPACK wire mechanics when the required capability exists;
- protocol negotiation and lower transport state;
- transport hard bounds/timeouts;
- normalized `HttpRequest`;
- `ResponseWriterInterface` and transport write pressure;
- authoritative runtime `RequestContext` cancellation/deadline state;
- generic application boot/warmup/drain/shutdown lifecycle;
- request admission and generic request resetter execution;
- generic worker recycling;
- structured coroutine/task scheduling and task-local propagation;
- host drivers for FPM, FrankenPHP, RoadRunner and Swoole/OpenSwoole.

## 3.2 Webrick owns

Webrick remains authoritative for application HTTP semantics:

- `Request` / `Response`;
- routing and compiled route artifacts;
- middleware;
- cookies;
- content negotiation;
- application HTTP policy;
- application/body/input limits above lower transport hard limits;
- uploaded-file request representation and multipart application semantics;
- HEAD semantics;
- range/conditional/cache response semantics;
- error rendering;
- runtime-neutral HTTP adaptation;
- public/static-asset HTTP policy;
- application body-production lifecycle;
- framework-neutral request-scoped DI use through InterMix;
- preserving existing standalone runtime adapters.

Webrick does **not** become a socket server, process supervisor, QUIC stack or generic async framework.

## 3.3 InterMix owns

InterMix 10.1 owns:

- DI definitions and resolution;
- singleton/transient/scoped lifetime semantics;
- logical DI scope identity;
- `ScopeContext` capture/attach/detach;
- scoped seeds;
- scope leave hooks;
- current-carrier scope reset;
- dynamic/compiled/deoptimized scope parity;
- concurrent cold scoped-construction collision protection.

Webrick must not reproduce these semantics in a second request-scope store.

## 3.4 Foundation owns

Foundation owns application/runtime policy above Webrick:

- runtime selection;
- application/release configuration;
- application graph composition;
- application execution state;
- authentication/principal/session state;
- DB transaction/application persistence state;
- application reset policy;
- structured application task APIs;
- mapping Runwire task-local propagation to InterMix 10.1 `ScopeContext` where required;
- release-generation/deployment policy.

## 3.5 Pathwise boundary

Pathwise remains filesystem/upload trust owner. Webrick owns HTTP file semantics, but trusted path containment/resolution stays outside Runwire.

Hard invariant:

> Runwire owns generic runtime/transport mechanics; Webrick owns HTTP application semantics; InterMix owns DI scope identity; Foundation owns application execution policy; Pathwise owns filesystem trust.

---

# 4. Deployment topology

Webrick 5 must explicitly support all of these paths.

## 4.1 Traditional SAPI/shared hosting

```text
Apache / LiteSpeed / CGI / FastCGI / PHP-FPM
                  ↓
          SapiRuntimeAdapter
                  ↓
               Webrick
                  ↓
             Foundation
```

Requirements:

- no Runwire installation required;
- no PCNTL/POSIX assumption;
- no long-running worker assumption;
- request state dies naturally with the host request, while explicit cleanup still remains correct;
- shared hosting remains first-class, not a degraded unsupported mode.

## 4.2 Runwire native prefork

```text
PHP CLI + PCNTL/POSIX
        ↓
Runwire native prefork
        ↓
Runwire HTTP transport
        ↓
Webrick Runwire adapter
```

Runwire owns worker/process mechanics.

## 4.3 Runwire native portable

```text
PHP CLI
no PCNTL/POSIX required
        ↓
Runwire portable single-process runtime
        ↓
Webrick Runwire adapter
```

This is a valid long-running mode where the host allows a persistent CLI process but not prefork/process-control extensions.

## 4.4 Runwire host drivers

For the Foundation-preferred Runwire path:

```text
FPM / FrankenPHP / RoadRunner / Swoole/OpenSwoole
                    ↓
            Runwire host driver
                    ↓
          normalized Runwire HTTP
                    ↓
         Webrick Runwire adapter
```

Runwire must not nest a native event loop/worker pool inside a host that already owns those mechanics.

## 4.5 Direct Webrick compatibility adapters

Standalone Webrick continues to support direct adapters independently of Runwire:

```text
SapiRuntimeAdapter
WorkermanRuntimeAdapter
SwooleRuntimeAdapter
RoadRunnerRuntimeAdapter
RunwireRuntimeAdapter
```

Do not remove or proxy existing standalone adapters through Runwire merely for architectural symmetry.

---

# 5. Dependency policy

## 5.1 InterMix

Webrick already production-depends on InterMix. Raise the Webrick 5 production baseline from the current `^10.0.4` line to **InterMix `^10.1`** so Webrick can rely on the released logical-scope contract.

Do not copy InterMix 10.1 scope propagation into Webrick compatibility helpers.

## 5.2 Runwire

Keep Runwire optional for standalone Webrick installations if the adapter boundary remains clean.

Preferred Composer policy:

```text
require:
  infocyph/intermix: ^10.1

require-dev:
  infocyph/runwire: ^1.0

suggest:
  infocyph/runwire: native/persistent Runwire runtime integration
```

If Runwire's 64-bit platform requirement makes the default Webrick development dependency matrix unnecessarily restrictive, isolate Runwire integration tests in an appropriate fixture/profile rather than making Webrick itself 64-bit-only.

Loading ordinary Webrick/SAPI classes must not require Runwire classes to exist.

---

# 6. Preserve and extend the existing runtime architecture

Current Webrick already has the correct high-level primitives:

```text
RuntimeAdapterInterface
RuntimeRequestContext
RuntimeCapabilities
RuntimeServer
InterMixRuntime
```

Extend these before inventing replacements.

Do not create a second parallel HTTP-runtime hierarchy solely for Runwire.

---

# 7. `RunwireRuntimeAdapter`

Add the smallest useful adapter, conceptually:

```text
src/Runtime/Http/RunwireRuntimeAdapter.php
```

It must implement the existing `RuntimeAdapterInterface`.

Its job is only to translate between stable Runwire public transport values and Webrick runtime values.

Expected inputs when selected:

- Runwire `HttpRequest` as native request;
- Runwire `ResponseWriterInterface` as native response writer.

The adapter must not depend on Runwire parser/frame/socket internals.

No Runwire `Connection`, HTTP/2 stream-state object, QUIC session or QPACK/HPACK object may leak into ordinary Webrick application handlers.

---

# 8. Runwire application bridge

Runwire 1.0 host/native drivers consume `RuntimeApplicationInterface`. Webrick needs a narrow optional bridge from that released runtime contract to `RuntimeServer`/`RunwireRuntimeAdapter`.

Do not reimplement Runwire's generic lifecycle engine in Webrick.

Preferred composition:

```text
Runwire driver
      ↓
RuntimeApplicationInterface bridge
      ↓
Runwire ApplicationLifecycle
      ↓
Webrick RuntimeServer
      ↓
RunwireRuntimeAdapter
      ↓
CompiledRouterKernel
```

The bridge may be a dedicated optional Runwire integration class if required, but it should delegate generic boot/warmup/admission/request reset/drain/shutdown behavior to Runwire's released lifecycle primitives rather than clone them.

Runwire's `ApplicationLifecycle` already owns:

- `RequestContext` activation;
- active-request tracking;
- admission;
- cancellation;
- resetter execution;
- request metrics;
- request-context completion;
- boot/warmup/drain/shutdown ordering.

Webrick supplies HTTP application dispatch and Webrick-specific cleanup only.

One layer must own response `end()` exactly once. Under the existing Webrick `RuntimeAdapterInterface`, the preferred model is that `RunwireRuntimeAdapter::write()` fully finalizes the Webrick response and Runwire `ApplicationLifecycle` is used with response auto-completion disabled for that handler. If implementation evidence favors the reverse, change the bridge deliberately and test exactly-once response completion; never allow both layers to call `end()` independently.

---

# 9. Runwire normalized request mapping

Runwire 1.0 already exposes a normalized `HttpRequest` containing:

- method;
- target;
- `ProtocolVersion` (`1.1`, `2`, `3`);
- normalized headers;
- `RequestBodyInterface`;
- peer/local address;
- encrypted/TLS state;
- authoritative Runwire `RequestContext`.

Use that object directly as the lower transport contract.

Do not reconstruct a Runwire-native request through PHP superglobals or an unnecessary PSR-7 hop.

Required mapping into Webrick:

- method;
- target/path/query;
- host/authority semantics;
- header multiplicity;
- cookies;
- body stream;
- trailer semantics where exposed/needed;
- remote/local transport metadata where Webrick intentionally exposes it;
- encrypted state;
- protocol version as diagnostic/request metadata where useful;
- cancellation/deadline/request identity through runtime-neutral Webrick context plumbing.

Avoid whole-body/header copies solely for adapter convenience.

---

# 10. HTTP/1.1, HTTP/2 and HTTP/3 parity

Runwire owns wire-version differences. Webrick owns normalized application semantics.

```text
HTTP/1.1 ─┐
HTTP/2 ───┼─> Runwire HttpRequest/ResponseWriter -> Webrick Request/Response
HTTP/3 ───┘
```

Equivalent requests must produce equivalent Webrick semantics across H1/H2/H3 except where the HTTP standards intentionally differ.

Verify parity for:

- method;
- path/query;
- host/authority;
- headers/duplicates;
- cookies;
- body;
- route matching;
- middleware;
- validation/input;
- status;
- response headers/body;
- HEAD;
- redirects;
- 404/405;
- exception/error rendering;
- streaming;
- file/range responses.

Webrick must not implement:

- HPACK;
- HTTP/2 frames/SETTINGS/GOAWAY/stream windows;
- QUIC;
- QPACK;
- HTTP/3 control streams;
- transport congestion control.

Those remain lower-runtime concerns.

HTTP/3 availability is capability-based because Runwire's QUIC transport is optional. Absence of QUIC must not affect ordinary Webrick startup.

---

# 11. Runtime request-context layering

There are three distinct concepts and they must not be collapsed.

## 11.1 Runwire `RequestContext`

Authoritative lower-runtime state:

- runtime request ID;
- monotonic start time;
- deadline;
- cancellation token/reason;
- runtime binding;
- bounded runtime attributes.

## 11.2 Webrick `RuntimeRequestContext`

HTTP adapter/application bridge state:

- canonical routing input;
- lazy Webrick `Request` factory;
- Webrick runtime capabilities;
- native transport handles when needed by the selected adapter.

Extend this context only with runtime-neutral metadata Webrick/Foundation genuinely need. Do not expose Runwire types through Webrick's ordinary public request API merely because the Runwire adapter exists.

If cancellation/deadline access is needed above the adapter, prefer a very small Webrick-neutral view/signal over a second independent cancellation state machine.

## 11.3 Webrick `Support\RequestContext`

This remains application-facing correlation/telemetry context attached to Webrick `Request` attributes.

Do not confuse it with the lower Runwire lifecycle context.

Where appropriate, map Runwire's generated request identity into Webrick correlation metadata instead of generating competing IDs, subject to Webrick/Foundation trust/correlation policy. Do not treat opaque runtime context IDs as authentication/security identities.

---

# 12. InterMix 10.1 integration baseline

`InterMixRuntime` currently wraps `Container|ProductionContainer` and provides `withinScope()`.

Extend that wrapper only as needed to expose the released generic InterMix 10.1 scope contract, conceptually:

```text
captureScopeContext()
withinScopeContext(...)
resetCurrentExecutionScope()
```

Use InterMix's real `ScopeContext` type because InterMix is already a required Webrick dependency.

Do not create `WebrickScopeContext`, duplicate scope stores or request-ID-keyed DI maps.

The existing semantic Webrick scope label remains:

```text
webrick.request
```

Scope identity remains a DI/application concept, not a transport stream ID.

---

# 13. Preserve zero-scope route hot paths

The compiled kernel currently avoids opening an InterMix request scope when the execution plan and middleware pipeline do not need scoped DI.

Preserve that optimization.

Do **not** force every routed request to:

- open an InterMix scope;
- capture a `ScopeContext`;
- allocate task-local state;
- materialize a full Webrick `Request`;

when a compiled direct route can execute without those facilities.

Logical-scope propagation is activated only when scoped DI actually exists and structured child work intentionally needs to share that scope.

Performance must remain evidence-driven; correctness takes priority if an execution plan genuinely requires scope.

---

# 14. Structured child work and InterMix scope propagation

Runwire child tasks are separate PHP Fibers. InterMix 10.1 intentionally treats physical Fibers as isolated by default.

One logical Webrick/Foundation request may nevertheless spawn structured child work that must resolve the **same request-scoped services**.

Correct composition:

```text
Webrick/Foundation owner execution
        ↓
enter InterMix `webrick.request`
        ↓
capture InterMix ScopeContext
        ↓
Foundation/consumer structured executor
        ↓
Runwire TaskLocal(SNAPSHOT) or equivalent owned propagation
        ↓
child Runwire task/Fiber
        ↓
InterMix withinScopeContext(...)
        ↓
shared logical request scope
        ↓
detach in finally
        ↓
Runwire joins/cancels all owned children
        ↓
owner closes `webrick.request`
        ↓
resetCurrentExecutionScope() defense in depth
```

Hard rules:

- Webrick does not automatically make every Fiber share the parent DI scope;
- independent Fibers/tasks remain isolated by default;
- a `ScopeContext` is opaque, process-local and non-serializable;
- never derive scope identity from HTTP/2 or HTTP/3 stream IDs;
- never persist a `ScopeContext` in sessions, queues, caches or cross-process messages;
- request children must detach/join before the owner closes the request scope;
- background/detached worker-generation work must not retain a request `ScopeContext` after request completion;
- InterMix remains the authority for scope liveness and concurrent cold scoped construction.

Foundation is the preferred owner of the Runwire task-local ↔ InterMix `ScopeContext` bridge because Foundation owns application structured-execution policy. Webrick should expose enough runtime/InterMix plumbing for that composition without becoming a structured-concurrency framework.

Add a Webrick integration fixture proving dynamic and compiled handler/middleware resolution sees the same scoped identity in explicitly attached Runwire child tasks.

---

# 15. Request-body streaming

Required native Runwire path:

```text
socket / H2 DATA / H3 DATA
        ↓
Runwire bounded transport + protocol flow control
        ↓
Runwire RequestBodyInterface
        ↓
Webrick BodyStream / Request
        ↓
application consumer
```

Do not default to:

```text
transport -> concatenate complete body -> copy -> Webrick string
```

for arbitrary/large requests.

Requirements:

- bounded buffering;
- correct partial reads;
- application body limit failures map predictably;
- unread HTTP/1.1 bodies are safely drained or the connection is not reused according to Runwire policy;
- unread/cancelled H2/H3 request streams are cleaned/reset below Webrick without contaminating sibling streams;
- Runwire body cancellation propagates to its `RequestContext`;
- request body state cannot survive request completion.

---

# 16. Multipart and uploaded files

Webrick continues to own request-level multipart/upload representation unless a separately justified ownership change is made.

Requirements:

- incremental/bounded multipart parsing where practical;
- no forced complete-body buffering for large uploads;
- temporary upload objects are request-owned;
- abort/rejection/cancellation cleans temporary state deterministically;
- Pathwise remains trusted filesystem/upload processing owner after application acceptance;
- Runwire supplies bytes/streaming/cancellation only;
- no Pathwise malware scanning/storage policy moves into the Runwire adapter.

---

# 17. Response adaptation and backpressure

Target flow:

```text
Webrick Response
      ↓
RunwireRuntimeAdapter
      ↓
Runwire ResponseWriterInterface
      ↓
protocol/connection/stream backpressure
      ↓
client
```

Runwire's writer exposes `start()`, `write()`, `end()`, `onDrain()`, `isStarted()` and `isEnded()`.

Webrick must use these semantics without building a second send queue.

Requirements:

- preserve status semantics;
- preserve duplicate response headers such as `Set-Cookie`;
- preserve HEAD semantics;
- incrementally stream `BodyStream`, iterable/chunked bodies, files and byte ranges;
- avoid materializing complete streaming/file output;
- stop application production after transport cancellation where safe;
- respect lower-layer backpressure rather than spinning or growing unbounded memory;
- H2/H3 stream backpressure must not block unrelated application streams through Webrick-global state;
- response writer start/end are exactly-once operations.

---

# 18. Application body lifetime vs transport lifetime

This boundary must be explicit.

A response has at least two relevant phases:

```text
application body production
        ↓
transport buffering/write
        ↓
network delivery to slow client
```

For a fully materialized body, application/DI request scope may close once application response production is complete.

For a lazy/streaming body, scoped services/resources may still be required while chunks are being produced. Therefore:

- do not close the owning Webrick/InterMix execution scope before a lazy body producer has finished or been cancelled;
- do not keep application DB/session/DI scope alive merely until every queued byte reaches a slow client if Runwire already owns/buffers transport completion;
- document the exact point at which Webrick considers application response production complete;
- cancellation during streaming must unwind the producer and request scope exactly once;
- generators/iterators/streams must not resume after request scope cleanup.

Add tests where a streaming body deliberately resolves/uses a request-scoped service during iteration, including cancellation/error paths.

---

# 19. Persistent and multiplexed request isolation

One process may serve many requests. One H2/H3 connection may carry overlapping independent streams.

Required model:

```text
worker
 ├─ H1 request A -> Webrick context A -> optional DI scope A -> cleanup
 ├─ H1 request B -> Webrick context B -> optional DI scope B -> cleanup
 ├─ H2 connection
 │    ├─ stream X -> context C -> scope C
 │    └─ stream Y -> context D -> scope D
 └─ H3 connection
      ├─ stream P -> context E -> scope E
      └─ stream Q -> context F -> scope F
```

Never retain request-owned state in process/adapter/connection globals:

- principal/user;
- session;
- parsed input;
- current route/route parameters;
- validation state;
- DB transaction state;
- request middleware mutable state;
- response headers/body;
- error/exception state;
- log correlation state;
- uploaded temporary state;
- InterMix request `ScopeContext`.

Sequential and interleaved isolation tests are release-blocking.

---

# 20. Application lifecycle contract

Do not create an Octane-like second lifecycle engine in Webrick.

Runwire already supplies generic:

```text
boot
warmup
admit request
activate RequestContext
handle
run resetters
complete RequestContext
drain
cancel active
shutdown
```

Webrick should provide only HTTP/application-specific callbacks and dispatch semantics.

Foundation may map Runwire lifecycle hooks/resetters to Foundation application lifecycle where Runwire is selected.

For SAPI/shared hosting, the same Webrick request semantics must work without Runwire: one host-owned request enters, dispatches and exits normally.

Exactly-once rules:

- a begun Webrick application request terminates exactly once;
- termination runs on normal response, early routing/middleware return, exception, deadline, transport cancellation and H2/H3 stream cancellation;
- cleanup failures must not silently replace the primary request failure;
- worker recycling is defense-in-depth, never the mechanism that makes request isolation correct.

---

# 21. InterMix cleanup contract

Every Webrick-owned InterMix request scope must be closed deterministically.

Preferred pattern conceptually:

```text
enter/withinScope(webrick.request)
    try dispatch/body production
    finally leave
finally resetCurrentExecutionScope()
```

Use `resetCurrentExecutionScope()` as defense in depth at an outer request boundary where appropriate. It affects the current execution carrier and is designed for framework/runtime cleanup.

Do not blindly call global container reset/mutation APIs that could corrupt concurrent H2/H3 streams.

InterMix 10.1 rejects unsafe graph/configuration mutation while shared/concurrent scope activity is live; Webrick should treat those failures as configuration/lifecycle errors rather than bypassing the guard.

---

# 22. Runtime capabilities

`RuntimeCapabilities` should contain only behavior Webrick genuinely needs to select HTTP/application behavior.

Current fields should be reviewed against the released runtimes. Useful capability concepts may include:

- persistent process/application;
- concurrent/interleaved requests;
- native request streaming;
- native response streaming;
- native file transfer;
- transport compression ownership;
- transport request-limit ownership;
- cancellation visibility;
- transport completion/drain visibility.

Do not copy Runwire's entire runtime capability matrix into Webrick.

Webrick generally does not need booleans for every protocol. The per-request protocol version can travel as request/runtime metadata, while H1/H2/H3 wire mechanics remain normalized below. Add protocol capability flags only if a Webrick behavior genuinely differs.

Avoid runtime-name switches in application logic; branch on capabilities or normalized request facts.

---

# 23. HTTP limit ownership

Compose limits rather than duplicating parsing/security work.

```text
Runwire
  raw framing/header/frame/QPACK/HPACK hard ceilings
  transport body/buffer ceilings
  H2/H3 stream/control limits
  slow transport deadlines
  connection/stream buffer limits

Webrick
  route/application body policy
  content-type policy
  form/input policy
  multipart/application upload policy
  middleware/application request limits

Foundation
  validates deployment/application configuration compatibility
```

Webrick must not reparse raw H1/H2/H3 bytes merely to enforce a rule Runwire already authoritatively enforces.

On SAPI/direct-host paths, preserve the existing Webrick/host request-limit behavior appropriate to those adapters.

---

# 24. Error ownership and mapping

Transport/protocol errors before a trustworthy normalized request generally remain below Webrick routing.

Examples staying in Runwire/native host transport:

- malformed H1 framing;
- invalid H2 frame/pseudo-header sequence;
- invalid H3/QPACK/control-stream state;
- transport hard header/frame ceiling;
- conflicting body framing;
- connection protocol failure;
- pre-request transport timeout.

Webrick owns:

- route not found/method not allowed;
- middleware rejection;
- application body/input policy;
- handler/controller failure;
- Webrick response policy failures.

Translate lower errors to Webrick responses only when the lower runtime provides a safe normalized request/response opportunity. Never route malformed transport as ordinary application input.

---

# 25. Cancellation, deadlines and client disconnect

Runwire `RequestContext` is authoritative for Runwire-path cancellation/deadline state.

Webrick requirements:

- observe transport cancellation where it affects application work;
- do not create an unrelated competing deadline clock/token when Runwire already owns one;
- stop body production/streaming promptly where safe;
- H2/H3 stream cancellation affects only its owning request unless the lower connection itself fails;
- cancellation must never leak to a sibling request;
- request/DI cleanup executes exactly once;
- client disconnect does not automatically roll back application side effects—transaction semantics remain application/Foundation policy.

For SAPI runtimes lacking rich cancellation signals, behavior remains ordinary synchronous PHP; absence of cancellation visibility is a capability difference, not an application correctness failure.

Do not introduce a Webrick-wide coroutine scheduler merely to support cancellation.

---

# 26. Trusted static/public asset fast path

Keep the optional fast path, but preserve ownership:

```text
request
  ↓
Webrick/Foundation public asset policy
  ↓
Pathwise trusted public-root resolution/containment
  ↓
Webrick file/range/cache/HEAD semantics
  ↓
selected runtime efficient file writer
  ↓
client
```

Hard rules:

- Runwire never converts an untrusted request path directly into filesystem authority;
- Webrick does not replace Pathwise containment/trust when Foundation uses Pathwise;
- static eligibility/precedence is explicit application policy;
- dotfile/traversal/symlink/private-storage escapes remain rejected;
- dynamic route/middleware semantics are not bypassed accidentally;
- zero-copy/sendfile-style acceleration is allowed only after trusted resolution;
- host runtimes may delegate static file service when configured outside the application.

This is an optimization, not a new filesystem security model.

---

# 27. File response primitive boundary

Webrick's existing `FileBody`/range semantics should remain authoritative.

Where Runwire can accelerate transfer, prefer already-authorized information such as:

- trusted/resolved file handle/path from the upper trust boundary;
- immutable metadata;
- selected byte range;
- known content length;
- cancellation/backpressure state.

Do not stabilize a new public zero-copy API until benchmarks show value over current streaming.

---

# 28. Worker drain/recycle boundary

Runwire may recycle workers using generic thresholds such as request/execution count, lifetime and memory.

Webrick must:

- behave correctly while the lower runtime drains;
- not admit work itself after Runwire has rejected/stopped admission;
- finish/cancel in-flight Webrick request producers according to lower policy;
- clean each request independently;
- expose only application-specific boot/warmup/shutdown behavior required by the bridge;
- never rely on worker restart to clean leaked request state.

Foundation owns deployment defaults; Runwire owns worker replacement mechanics.

On SAPI/FPM/shared hosting, host-owned process/request lifecycle remains authoritative and no Runwire recycling feature is assumed.

---

# 29. Existing direct adapter coexistence

Keep these independent paths valid:

```text
SAPI              -> SapiRuntimeAdapter       -> Webrick
Workerman         -> WorkermanRuntimeAdapter  -> Webrick
Swoole/OpenSwoole -> SwooleRuntimeAdapter     -> Webrick
RoadRunner        -> RoadRunnerRuntimeAdapter -> Webrick
Runwire           -> RunwireRuntimeAdapter    -> Webrick
```

For Foundation's preferred path, Runwire may itself adapt FPM/FrankenPHP/RoadRunner/Swoole. That lets Foundation usually compose one Webrick Runwire integration rather than selecting a different Webrick adapter for every Runwire host driver.

That simplification must not remove standalone Webrick interoperability.

---

# 30. Foundation bridge target

Preferred high-performance Foundation path:

```text
Foundation runtime selection
        ↓
Runwire driver
  native / FPM / FrankenPHP / RR / Swoole
        ↓
Runwire RuntimeApplicationInterface bridge
        ↓
Runwire normalized HTTP + RequestContext
        ↓
Webrick RunwireRuntimeAdapter / RuntimeServer
        ↓
CompiledRouterKernel
        ↓
InterMix 10.1 request scope when required
        ↓
Foundation/application handler
        ↓
Webrick Response/body producer
        ↓
Runwire ResponseWriter
```

Shared-hosting path remains:

```text
host request
   ↓
SapiRuntimeAdapter
   ↓
Webrick
   ↓
Foundation
```

Webrick must remain usable without Foundation.

Foundation should consume Runwire cancellation/deadline/lifecycle state through the bridge rather than duplicating it, and should compose InterMix `ScopeContext` with Runwire structured tasks when application child work needs request-scope inheritance.

---

# 31. Infbyte acceptance role

Infbyte is the real application proof after Webrick/Foundation integration.

Exercise:

- traditional request-scoped SAPI mode;
- FPM;
- Runwire native portable;
- Runwire native prefork where available;
- FrankenPHP/RoadRunner/Swoole paths where available;
- H1/H2/H3 application parity where transport capabilities exist;
- authentication/session state;
- validation/input;
- sequential keep-alive requests;
- multiplexed request isolation;
- structured child-task scoped DI propagation;
- exceptions followed by clean subsequent requests;
- streaming responses that use scoped services;
- cancellation/disconnect;
- file/range/static-asset behavior;
- worker drain/recycle;
- memory/state soak behavior.

Infbyte proves distribution/application behavior; Webrick's own unit/integration suite remains authoritative for Webrick semantics.

---

# 32. Test plan

Release-blocking focused tests should include:

## 32.1 Optional dependency / portability

- Webrick core autoloads and runs without Runwire installed;
- SAPI path works without PCNTL/POSIX;
- no base Webrick API requires Runwire types;
- InterMix 10.1 is the minimum integrated DI baseline;
- Runwire integration tests do not require PCNTL/POSIX merely to test the adapter/structured task bridge.

## 32.2 Runwire request mapping

- GET/POST/query;
- host/authority;
- duplicate request headers;
- cookies;
- encrypted/peer/local metadata where supported;
- lazy Webrick request materialization;
- request identity mapping;
- body stream adaptation;
- no accidental superglobal dependence.

## 32.3 Response mapping

- status/headers;
- duplicate `Set-Cookie`;
- known-length bodies;
- streaming/chunked bodies;
- HEAD;
- file/range;
- exactly-once writer start/end;
- slow writer/backpressure;
- writer cancellation/error.

## 32.4 H1/H2/H3 parity

- equivalent normalized routing/handler response;
- H1 keep-alive isolation;
- H2 interleaved stream isolation;
- H3 interleaved stream isolation where QUIC test capability exists;
- H2/H3 stream cancellation affects only the owning request;
- malformed lower protocol state never becomes a Webrick routed request.

## 32.5 InterMix 10.1

- dynamic and compiled request scopes;
- sequential request scope cleanup;
- independent Fiber/task isolation by default;
- explicit `ScopeContext` child attachment shares intended scoped service identity;
- siblings share the owning request scope only when explicitly attached;
- child nested scope remains carrier-local;
- owner cannot close while child attachment remains live;
- child exceptions/cancellation detach in `finally`;
- `resetCurrentExecutionScope()` clears stranded current-carrier state;
- no `ScopeContext` leaks into the next request;
- concurrent cold scoped-service collision behavior matches InterMix 10.1 contract;
- zero-scope direct route does not pay mandatory scope-capture overhead.

## 32.6 Streaming lifetime

- lazy response body can use scoped service until production finishes;
- scope closes after normal streaming completion;
- scope closes after producer exception;
- scope closes after client cancellation;
- producer never resumes after scope cleanup;
- slow network flush does not unnecessarily retain completed application state.

## 32.7 Lifecycle

- normal request cleanup exactly once;
- middleware short-circuit cleanup;
- route error cleanup;
- handler exception cleanup;
- deadline cleanup;
- transport cancellation cleanup;
- drain/recycle with active requests;
- Runwire resetter failure preserves primary error ordering;
- subsequent request remains clean after failure.

## 32.8 Static/file boundary

- trusted static asset success;
- HEAD/range/conditional behavior;
- traversal rejection;
- private/dotfile rejection;
- symlink/root escape rejection according to Pathwise policy;
- ordinary streamed fallback when native transfer acceleration is absent.

---

# 33. Semantic parity matrix

Compare equivalent Webrick application behavior across:

```text
SAPI/generic request-owned PHP
Workerman direct
Swoole/OpenSwoole direct
RoadRunner direct
Runwire HTTP/1.1
Runwire HTTP/2
Runwire HTTP/3 where available
```

Also exercise Foundation-preferred Runwire host drivers as integration environments:

```text
Runwire FPM
Runwire FrankenPHP
Runwire RoadRunner
Runwire Swoole/OpenSwoole
Runwire native portable
Runwire native prefork
```

Transport capabilities may differ. Webrick application semantics must remain intentional and documented.

---

# 34. Performance plan

Keep performance evidence layered so transport speed is not confused with Webrick application overhead.

Measure at least:

```text
Webrick compiled kernel only
SAPI/FPM real HTTP
Workerman + Webrick
Runwire H1 + Webrick
Runwire H2 + Webrick
Runwire H3 + Webrick where available
Foundation + Webrick + Runwire
Infbyte full application
```

Measure:

- throughput;
- p50/p95/p99;
- CPU;
- memory/RSS growth where observable;
- adapter allocations/copies;
- lazy request materialization rate;
- zero-scope route overhead;
- scoped route overhead;
- explicit InterMix `ScopeContext` propagation overhead;
- structured child-task overhead;
- keep-alive throughput;
- H2/H3 multiplexed throughput;
- body streaming throughput;
- slow-client memory behavior;
- file normal vs accelerated path;
- lifecycle/reset overhead;
- error/cancellation rate.

Do not add a Webrick-specific scheduler or bypass application semantics just to improve benchmark numbers.

---

# 35. Security acceptance

Release-blocking security properties:

- malformed Runwire wire input cannot reach Webrick as trusted normalized request state;
- Webrick does not weaken Runwire transport hard bounds;
- no unbounded adapter body/header/file buffering;
- H2/H3 sibling streams remain application-state isolated;
- request cancellation cannot contaminate another request;
- InterMix request scope cannot outlive its owner through a stale propagated `ScopeContext`;
- background work cannot retain request scope accidentally;
- request-scoped objects do not leak across persistent requests;
- dynamic/compiled InterMix mutation guards are not bypassed during concurrent work;
- Webrick correlation/request IDs are not treated as authorization identities;
- static fast path cannot escape trusted public roots;
- Pathwise remains filesystem/upload trust owner;
- ReqShield remains input/intent validation owner;
- Webrick gains no raw process/shell API;
- compatibility adapters continue to work when Runwire is absent.

---

# 36. Documentation updates

Document the finished behavior, including:

- Runwire as optional native/persistent integration;
- InterMix 10.1 requirement and logical scope propagation;
- ordinary SAPI/shared-hosting deployment as first-class;
- native portable vs native prefork Runwire deployment;
- Runwire host drivers;
- H1/H2/H3 normalized Webrick semantics;
- request context layers and cancellation/deadlines;
- persistent-request isolation;
- structured child-task DI scope propagation;
- streaming application-lifetime boundary;
- transport backpressure;
- application vs transport limit ownership;
- static/public-asset Pathwise boundary;
- worker drain/recycle relationship;
- direct Workerman/Swoole/RoadRunner/SAPI adapter coexistence;
- reverse-proxy/TLS/H2/H3 termination expectations.

Do not document unavailable acceleration as guaranteed. Capability-based features must be described as such.

---

# 37. Implementation order

```text
1. Raise InterMix production baseline to ^10.1.
2. Freeze regression coverage for existing SAPI/Workerman/Swoole/RoadRunner adapters.
3. Add optional released Runwire ^1.0 integration dependency for tests/adapter work.
4. Extend InterMixRuntime with the minimal 10.1 scope-context/reset surface needed by framework integration.
5. Extend RuntimeRequestContext/RuntimeCapabilities only with proven runtime-neutral metadata needed by the bridge.
6. Implement the smallest RunwireRuntimeAdapter against released HttpRequest/ResponseWriterInterface.
7. Add the optional Runwire RuntimeApplicationInterface bridge using Runwire ApplicationLifecycle rather than duplicating lifecycle logic.
8. Prove H1 request/response parity and exactly-once response completion.
9. Prove H2 normalization, interleaved request isolation and cancellation.
10. Prove H3 normalization/stream isolation where QUIC capability is available; keep absence of QUIC non-fatal.
11. Preserve bounded request-body streaming and response backpressure.
12. Harden application body-production lifetime for lazy/streaming responses.
13. Integrate/test InterMix 10.1 explicit ScopeContext propagation for structured child work without penalizing zero-scope routes.
14. Add exactly-once request cleanup/reset acceptance across success/error/cancellation/deadline paths.
15. Add trusted static/public asset integration boundary with Pathwise composition.
16. Add Runwire native portable/prefork and host-driver integration acceptance while preserving generic SAPI/shared-hosting behavior.
17. Add Foundation bridge fixture.
18. Add Infbyte end-to-end acceptance.
19. Benchmark and tune only measured adapter/scope/streaming overhead.
20. Finalize Webrick 5 release documentation and Foundation handoff.
```

---

# 38. Completion gate

This Webrick plan closes only when:

- [ ] Webrick production baseline uses released InterMix 10.1 or newer compatible 10.x;
- [ ] Runwire integration targets released Runwire 1.0 public contracts;
- [ ] Runwire remains optional for ordinary/standalone Webrick use;
- [ ] Webrick remains runnable on ordinary SAPI/shared hosting without PCNTL/POSIX/persistent workers;
- [ ] `RunwireRuntimeAdapter` uses existing Webrick runtime abstractions rather than a parallel HTTP stack;
- [ ] the Runwire application bridge reuses Runwire generic lifecycle/cancellation/reset mechanisms rather than recreating them;
- [ ] H1/H2/H3 normalized requests map to the same intended Webrick application semantics where each protocol is available;
- [ ] absence of QUIC/H3 is a capability absence, not a Webrick startup failure;
- [ ] Workerman/SAPI/Swoole/RoadRunner direct adapters remain available;
- [ ] request bodies and responses stream without unnecessary whole-message buffering;
- [ ] Runwire response backpressure is honored;
- [ ] response writer finalization is exactly once;
- [ ] lazy/streaming application body production keeps required request scope alive and releases it deterministically afterward;
- [ ] Runwire cancellation/deadlines are not duplicated by an inconsistent Webrick state machine;
- [ ] sequential H1 and interleaved H2/H3 request isolation is proven;
- [ ] InterMix 10.1 explicit `ScopeContext` propagation is proven for structured child tasks;
- [ ] independent child Fibers/tasks remain isolated unless propagation is explicit;
- [ ] zero-scope compiled routes retain their optimized no-scope path;
- [ ] `resetCurrentExecutionScope()` is integrated where appropriate as defense-in-depth cleanup;
- [ ] malformed lower protocol state never becomes a normal routed Webrick request;
- [ ] trusted static-file behavior preserves Pathwise/public-root and Webrick HTTP semantics;
- [ ] worker drain/recycling is not used as a substitute for request cleanup;
- [ ] Foundation bridge passes request/Fiber/structured-task/isolation tests;
- [ ] Infbyte passes request-scoped and persistent-runtime acceptance;
- [ ] direct Runwire+Webrick performance evidence is recorded without benchmark-only semantic bypasses;
- [ ] Foundation 3 can consume Webrick over released Runwire 1.0 while still retaining a generic SAPI/shared-hosting path.

---

# 39. Non-goals

Do not add to Webrick as part of this pass:

- an event loop;
- a socket server;
- an HTTP/2 frame/HPACK engine;
- QUIC/QPACK/HTTP/3 wire implementation;
- a coroutine scheduler;
- task channels/mutex/semaphore primitives;
- PCNTL/POSIX supervision;
- process command execution;
- arbitrary shell execution;
- queue worker semantics;
- a duplicate InterMix scope/context implementation;
- request-ID-based DI scope storage;
- Pathwise filesystem/storage implementation;
- Foundation application reset registry;
- Laravel-style cloned application/container sandboxes;
- Laravel-specific warm/flush/reset listeners;
- host-specific shared table/cache systems;
- removal of existing Webrick runtime adapters.

Runwire owns generic runtime mechanics. InterMix owns DI scope semantics. Webrick owns application-facing HTTP. Foundation owns application execution policy. Infbyte proves the integrated stack.