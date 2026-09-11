# Webrick 5 — Runwire Native Runtime Adapter & Persistent HTTP Plan

## Status

Target: **Webrick 5.x + Runwire 1.0**

Branch: `webrick-5/runwire-runtime-adapter`

Primary consumer: **Foundation 3**

Primary end-to-end acceptance application: **Infbyte**

Canonical lower-runtime plan: `infocyph/Runwire` → `docs/plans/runwire-1.0-foundation-3-launch-plan.md`

Priority:

> HTTP correctness → persistent-request isolation → streaming/backpressure → security bounds → low-copy adaptation → static/file efficiency → performance → compatibility

This plan makes Runwire the native Infocyph persistent-server transport for Webrick while preserving Webrick as the sole owner of application-facing HTTP request/response/routing semantics. It also formalizes the application-runtime lifecycle needed for Foundation/Infbyte persistent workers without copying Laravel Octane's framework-specific container cloning/reset model.

---

# 1. Ownership boundary

## Runwire owns

- master/worker process supervision;
- `pcntl` / `posix` process mechanics;
- event loop;
- TCP/UDP/Unix/TLS listeners;
- connection lifecycle;
- socket input/output buffers and backpressure;
- native HTTP/1.1 wire framing/parsing/serialization;
- native HTTP/2 frame/stream/HPACK/flow-control mechanics;
- TLS ALPN negotiation for `h2` / `http/1.1`;
- transport-level header/body/time/connection/stream hard bounds;
- client disconnect detection;
- response/file transport writing;
- generic worker lifecycle/recycling mechanics.

## Webrick owns

- `Request` / `Response` semantics;
- `RuntimeAdapterInterface` and HTTP runtime adaptation;
- routing and compiled route artifacts;
- middleware;
- content negotiation;
- cookies;
- application HTTP security policy;
- application request limits above Runwire transport hard limits;
- uploaded-file request representation;
- error rendering;
- HEAD/range/application response semantics;
- public/static-asset HTTP policy;
- runtime request context passed to Foundation/application code;
- application-facing request begin/end semantics.

## Foundation owns

- selecting the Runwire runtime driver;
- app/release configuration;
- DI/InterMix graph;
- fresh execution scope per HTTP request/HTTP2 stream;
- authentication/session/database/application state;
- state lifetime/reset policy;
- deployment/release-generation policy.

Hard invariant:

> Webrick must not grow its own socket/event-loop/process server, and Runwire must not grow Webrick routing/middleware/application HTTP semantics.

---

# 2. Preserve existing runtime adapters

Do not remove or degrade:

- `SapiRuntimeAdapter`;
- `WorkermanRuntimeAdapter`;
- `SwooleRuntimeAdapter`;
- `RoadRunnerRuntimeAdapter`.

Runwire becomes the **native Infocyph / Foundation-preferred** runtime, not an exclusive runtime.

Target adapter set:

```text
Webrick RuntimeAdapterInterface
  ├─ SapiRuntimeAdapter
  ├─ WorkermanRuntimeAdapter
  ├─ SwooleRuntimeAdapter
  ├─ RoadRunnerRuntimeAdapter
  └─ RunwireRuntimeAdapter     <- native Foundation path
```

FrankenPHP/FPM behavior may arrive through Foundation/Runwire host adaptation without forcing a duplicate Webrick application contract.

---

# 3. Dependency policy

Webrick should remain usable without Runwire.

Recommended Composer policy:

- keep `infocyph/runwire` out of unconditional production `require` if adapter isolation permits it;
- use `require-dev: infocyph/runwire:^1.0` for adapter tests/reference integration after release;
- add Composer `suggest` text for Runwire native server integration;
- development branches may temporarily use the coordinated Runwire branch;
- final Foundation 3 release must consume released Runwire 1.0.

If signatures would force Runwire classes to load merely by loading Webrick core, redesign the adapter boundary rather than making every Webrick installation install a process/server runtime.

---

# 4. `RunwireRuntimeAdapter`

Preferred location:

```text
src/Runtime/Http/RunwireRuntimeAdapter.php
```

It must implement Webrick's existing:

```text
RuntimeAdapterInterface
```

Do not create a parallel HTTP-runtime abstraction.

Where a native wrapper materially reduces copying, a narrowly scoped adapter value may be added, but only if the existing transport/runtime context cannot adapt Runwire efficiently and correctly.

---

# 5. Version-neutral native request adaptation

The Runwire adapter consumes the stable Runwire HTTP transport contract, not parser/socket/frame internals.

Required request information:

- method;
- request target;
- path/query;
- protocol version (`1.1` or `2` for Runwire 1.0 native);
- headers with duplicate/multi-value semantics preserved;
- request body stream;
- trailers where supported;
- remote/local address metadata;
- TLS metadata where intentionally exposed;
- cancellation/disconnect signal;
- request/stream identity only when useful and non-authoritative;
- upload/body streaming information needed to construct Webrick semantics.

Requirements:

- no reconstruction through PHP superglobals for native Runwire;
- no unnecessary PSR-7 conversion hop;
- no full-body copy merely to cross the adapter;
- no header flattening that changes semantics;
- no Runwire `Connection`, HTTP/2 stream-state or parser object leaked to ordinary handlers;
- request-scoped Webrick data must not be retained by worker-global Runwire/Webrick adapter objects.

HTTP/1.1 and HTTP/2 must normalize into the same Webrick-facing application semantics.

---

# 6. HTTP/1.1 and HTTP/2 application parity

Runwire owns the wire distinction; Webrick should generally not.

```text
HTTP/1.1 request ─────┐
                      ├─ Runwire HTTP transport -> Webrick Request -> same kernel
HTTP/2 stream ────────┘
```

Verify parity for equivalent requests across both protocols:

- method/path/query;
- host/authority mapping;
- request headers and duplicate semantics;
- cookies;
- body/trailers where supported;
- route matching;
- middleware;
- validation/input;
- response status/headers/body;
- HEAD;
- redirects;
- errors;
- streaming/file bodies.

Webrick must not implement HPACK, HTTP/2 frame state, stream flow-control windows, SETTINGS or GOAWAY; those remain Runwire responsibilities.

---

# 7. Request body streaming

Required path:

```text
socket / HTTP2 DATA
  -> Runwire bounded transport/flow-control
  -> Runwire request body stream
  -> Webrick BodyStream / Request
  -> application consumer
```

Do not default to full-body concatenation for arbitrary or large requests.

Requirements:

- bounded buffering;
- correct partial reads;
- body-limit failures map consistently;
- unread HTTP/1.1 bodies are safely drained or connection-closed before reuse;
- unread/cancelled HTTP/2 streams are reset/cleaned through Runwire without affecting sibling streams;
- disconnect/cancellation does not contaminate the next request;
- request body state dies with its request execution.

---

# 8. Uploaded files

Webrick owns request-level uploaded-file representation. Pathwise owns filesystem/upload trust-boundary processing after the application accepts an upload.

Runwire only supplies transport/body streaming primitives.

Requirements:

- multipart parsing remains Webrick-owned unless a future explicit ownership change is justified;
- multipart parsing should be incremental and bounded;
- temporary upload objects cannot survive into another persistent request;
- rejected/aborted uploads are cleaned deterministically;
- no Pathwise storage/scanning policy moves into Runwire.

---

# 9. Response adaptation and backpressure

Target flow:

```text
Webrick Response
      ↓
RunwireRuntimeAdapter
      ↓
Runwire protocol response writer
      ↓
bounded connection/stream send state
      ↓
client
```

Requirements:

- preserve status/reason semantics where protocol applicable;
- preserve duplicate headers such as `Set-Cookie`;
- preserve Webrick HEAD semantics;
- stream `BodyStream`, file/range bodies and iterable/chunked bodies incrementally;
- avoid materializing complete streaming/file responses;
- propagate send-side pressure so producers cannot create unbounded memory;
- HTTP/2 response DATA must honor Runwire stream + connection flow control;
- stop producing promptly after client/stream cancellation;
- distinguish application response production from transport flush/completion;
- after-response hooks run at the documented semantic boundary, not merely when data is queued.

---

# 10. Persistent request isolation

One process may handle many requests; one HTTP/2 connection may contain overlapping requests.

Required model:

```text
worker
 ├─ HTTP/1 request A -> RuntimeRequestContext A -> Foundation execution A -> cleanup
 ├─ HTTP/1 request B -> RuntimeRequestContext B -> Foundation execution B -> cleanup
 └─ HTTP/2 connection
       ├─ stream 1 -> RuntimeRequestContext C -> Foundation execution C
       └─ stream 3 -> RuntimeRequestContext D -> Foundation execution D
```

Never cache in persistent adapter/connection state:

- principal/user;
- session;
- request input;
- route parameters/current route;
- application validation state;
- DB transaction/context;
- per-request middleware state;
- response headers/body;
- exception/error state;
- application logger context.

Sequential and interleaved isolation tests are release-blocking.

---

# 11. Explicit request lifecycle contract

Octane demonstrates how many framework services can accidentally retain request state in a persistent worker. Webrick should take the lifecycle lesson without taking Laravel's framework-specific reset implementation.

Webrick should expose/reuse a narrow application-facing lifecycle:

```text
application/worker boot
request begin
request dispatched
response produced
request terminate/end
application/worker shutdown
```

Requirements:

- each request begins exactly once;
- each begun request terminates exactly once;
- termination runs on success, short-circuit, exception, disconnect, timeout/cancellation and stream reset;
- lifecycle callbacks cannot mutate Runwire supervisor internals;
- no framework-wide container cloning requirement is introduced in Webrick;
- Foundation may map begin/end to its own explicit execution scopes and cleanup registry.

Webrick owns HTTP lifecycle semantics; Foundation owns application-service reset policy.

---

# 12. Runtime capabilities

Use Webrick `RuntimeCapabilities` only for HTTP/application behavior that Webrick must know.

Useful capabilities include:

- persistent application/worker behavior;
- streaming request/response;
- transport-completion visibility;
- cancellation visibility;
- native file transfer capability;
- HTTP/2 request semantics available;
- concurrent/interleaved request capability.

Do not duplicate generic Runwire capabilities such as fork, POSIX identity or event backend.

Consumers should branch on capabilities only when behavior genuinely differs, not scatter runtime-name switches.

---

# 13. HTTP limit ownership

Runwire transport hard bounds and Webrick application limits must compose.

```text
Runwire
  request-line/header/frame/header-list hard bounds
  hard body framing/buffer ceilings
  HTTP/2 stream/control/HPACK bounds
  slow-header/body/stream timeouts
  connection/stream buffer ceilings

Webrick
  route/application body policy
  content-type policy
  form/input policy
  multipart/application upload policy
  middleware/application request limits
```

Foundation validates incompatible configuration at startup.

Webrick must not reparse raw HTTP just to enforce a transport rule already authoritatively enforced by Runwire.

---

# 14. Error mapping

Runwire transport/protocol failures that occur before a valid Webrick request generally stay below routing.

Examples:

- malformed HTTP/1 framing;
- invalid HTTP/2 pseudo-headers/frame sequence;
- header ceiling exceeded;
- body framing conflict;
- transport timeout before a valid request exists;
- connection/stream protocol failure.

Webrick errors include:

- routing/method outcome;
- middleware rejection;
- handler failure;
- content/application validation;
- application body policy after transport acceptance.

Translate only failures for which a valid application HTTP response can safely be produced.

---

# 15. Client cancellation

Requirements:

- observe cancellation/disconnect where Runwire exposes it;
- stop streaming work promptly where safe;
- HTTP/2 stream reset cancels only the owning request unless connection failure requires broader termination;
- cancellation cannot leak into another request/stream;
- Foundation request cleanup runs exactly once;
- side effects are not automatically rolled back merely because the client disconnects—application transaction policy remains authoritative.

Do not introduce a Webrick-wide async framework solely for cancellation.

---

# 16. Trusted static/public asset fast path

Add an optional fast path so a trusted public asset can avoid full dynamic application routing where policy allows it.

Correct ownership:

```text
Runwire request
      ↓
Webrick/Foundation public-asset policy
      ↓
Pathwise trusted public-root resolution / containment
      ↓
Webrick file/range/cache HTTP semantics
      ↓
Runwire efficient file-response primitive
      ↓
client
```

Hard rules:

- Runwire must never turn raw request paths into filesystem reads by itself;
- Webrick does not bypass Pathwise/trusted public-root containment where Foundation uses Pathwise for resolution;
- static eligibility and HTTP semantics remain Webrick/Foundation policy;
- dynamic routes retain normal middleware/routing semantics unless an explicit public-asset precedence policy says otherwise;
- HEAD, Range, conditional/cache headers and content-type behavior remain application HTTP semantics;
- transfer may use streaming, `sendfile`/zero-copy-style support or equivalent only after trusted resolution;
- fast-path behavior must not expose dotfiles, traversal, symlink escapes, private storage or generated application artifacts;
- host runtimes that natively serve static files may report/delegate capability rather than forcing the Runwire path.

The fast path is an optimization after trust resolution, not a new filesystem security boundary.

---

# 17. Static file response primitive boundary

Webrick should expose/retain a file response representation that can be efficiently consumed by Runwire without handing Runwire arbitrary path authority.

Preferred conceptual inputs to the lower transport:

- already-authorized/resolved file handle or trusted file descriptor where practical;
- immutable file metadata needed for output;
- selected byte range;
- known length;
- cancellation/backpressure signal.

Avoid APIs where Runwire receives an attacker-derived path and performs its own application-level authorization.

Benchmark normal streamed file output against any native zero-copy/sendfile path before stabilizing extra public APIs.

---

# 18. Runtime lifecycle and worker recycling boundary

Webrick does not own worker restart policy, but must behave correctly when a worker is recycled/drained.

Runwire may recycle on generic thresholds such as:

```text
max executions
max worker lifetime
max memory
idle policy
graceful drain deadline
```

Webrick must:

- stop accepting new application executions once the host signals drain where applicable;
- allow in-flight requests/streams to finish within lower-layer policy;
- terminate each Webrick request context deterministically;
- expose application shutdown hook only at the appropriate worker/application boundary;
- never use recycling as a substitute for request-state cleanup.

Foundation chooses application/deployment defaults; Runwire owns process replacement mechanics.

---

# 19. Existing Workerman/host adapter coexistence

Do not rewrite existing adapters to proxy through Runwire.

```text
Workerman transport -> WorkermanRuntimeAdapter -> Webrick
Runwire transport   -> RunwireRuntimeAdapter   -> Webrick
Swoole transport    -> SwooleRuntimeAdapter    -> Webrick
RoadRunner          -> RoadRunnerRuntimeAdapter-> Webrick
SAPI/FPM            -> SapiRuntimeAdapter      -> Webrick
```

Foundation may choose to route host-runtime selection through Runwire's host-driver abstraction, but Webrick's existing standalone adapters remain legitimate compatibility/test surfaces.

---

# 20. Foundation bridge

Foundation's native flow:

```text
Runwire HTTP server
   ↓
Webrick RunwireRuntimeAdapter
   ↓
Foundation begin web execution
   ↓
Webrick compiled kernel
   ↓
Webrick Response
   ↓
Foundation terminate execution in finally
   ↓
Runwire writer/completion
```

Exact ordering between Foundation termination and transport completion must follow the documented after-response contract; request-owned resources must not remain alive merely because a slow client is still receiving already-produced bytes unless an explicit streaming body requires them.

Webrick must remain usable without Foundation.

---

# 21. Infbyte acceptance role

Infbyte is the primary real-application proof that the Webrick/Runwire/Foundation lifecycle works outside synthetic fixtures.

Use Infbyte to exercise:

- dynamic routing/middleware;
- authentication/session state where configured;
- validation/input;
- repeated keep-alive requests;
- HTTP/2 multiplexed requests;
- exceptions followed by successful requests;
- streaming/file responses;
- static/public asset fast path;
- client disconnect;
- worker recycle/reload;
- runtime-driver portability.

Webrick unit/integration tests remain authoritative for Webrick behavior; Infbyte supplies end-to-end distribution acceptance.

---

# 22. Test matrix

Add focused tests for:

- GET/POST/query;
- duplicate request headers;
- duplicate response headers / `Set-Cookie`;
- known-length and chunked HTTP/1 bodies;
- HTTP/2 normalized request semantics;
- streaming request body;
- large bounded request;
- body limit failure;
- HTTP/1 keep-alive sequential isolation;
- HTTP/2 interleaved stream isolation;
- malformed transport never routed;
- HEAD;
- 404/405;
- streaming response;
- file/range response;
- trusted static-file fast path;
- traversal/private-path/static-policy rejection;
- client disconnect during read/write;
- HTTP/2 stream reset during application work;
- backpressure with slow reader;
- exception/error response;
- deterministic request cleanup after every termination path;
- worker drain/recycle while requests are active;
- Foundation bridge fixture;
- Infbyte end-to-end fixture;
- Webrick core can load without Runwire when optional integration is absent.

---

# 23. Semantic parity matrix

For equivalent valid requests compare at least:

```text
SAPI/FPM
Workerman
Runwire HTTP/1.1
Runwire HTTP/2
```

and where available:

```text
Swoole/OpenSwoole
RoadRunner
FrankenPHP through Foundation/Runwire host integration
```

Verify routing, headers, cookies, query/form/body, request lifecycle, status, response headers/body, HEAD, 404/405, middleware, exception mapping, streaming and file semantics.

Transport capabilities can differ; application semantics must remain intentional/documented.

---

# 24. Performance benchmarks

Keep layered benchmarks:

1. Webrick kernel-only.
2. Existing Apache/FPM real HTTP.
3. Workerman + Webrick.
4. Runwire HTTP/1.1 + Webrick.
5. Runwire HTTP/2 + Webrick.
6. Foundation + Webrick + Runwire.
7. Infbyte full application.

Measure:

- requests/sec;
- p50/p95/p99;
- CPU;
- RSS/memory growth;
- adapter allocations/copies;
- keep-alive throughput;
- HTTP/2 multiplexing;
- streaming throughput;
- static-file normal vs fast path;
- slow-client memory;
- request lifecycle cleanup overhead;
- error rate.

No benchmark-only bypass of Webrick/Foundation semantics.

---

# 25. Security acceptance

Release-blocking properties:

- malformed Runwire framing cannot reach routing as trusted request state;
- Webrick cannot bypass Runwire hard transport bounds;
- no unbounded adapter header/body/file copies;
- no state from one persistent request survives into another;
- HTTP/2 sibling streams remain request-isolated;
- cancellation/reset cannot leave stale runtime request context;
- header semantics are preserved without injection/flattening bugs;
- static fast path cannot escape trusted public asset roots;
- Pathwise remains filesystem/upload trust owner;
- ReqShield remains validation owner;
- Webrick acquires no process/shell APIs;
- compatibility adapters remain unaffected when Runwire is not selected.

---

# 26. Documentation

Document:

- Runwire as native Infocyph persistent server option;
- optional dependency model;
- standalone Webrick + Runwire example;
- Foundation 3/Infbyte relationship;
- HTTP/1.1 and HTTP/2 normalized semantics;
- persistent request lifecycle and cleanup expectations;
- streaming/backpressure;
- transport hard bounds vs Webrick application limits;
- client cancellation;
- static/public asset fast path and Pathwise boundary;
- worker recycling/drain relationship;
- Workerman/Swoole/RoadRunner/SAPI coexistence;
- reverse-proxy/TLS termination choices.

---

# 27. Implementation order

```text
1. Freeze version-neutral Runwire HTTP transport contract.
2. Add require-dev/reference Runwire integration.
3. Implement smallest RunwireRuntimeAdapter.
4. Prove HTTP/1.1 request/response parity.
5. Add HTTP/2 normalization/parity without exposing frame internals.
6. Preserve body streaming and response backpressure.
7. Formalize request begin/end/cancellation/termination semantics.
8. Add persistent sequential + interleaved isolation tests.
9. Add trusted public-asset/static fast path with Pathwise/Foundation composition.
10. Add worker drain/recycle lifecycle acceptance.
11. Add Foundation bridge and Infbyte end-to-end acceptance.
12. Benchmark and tune only measured adapter/transfer overhead.
13. Release coordinated with Runwire 1.0 / Foundation 3.
```

---

# 28. Completion gate

This plan closes only when:

- [ ] Runwire 1.0 exposes a stable version-neutral HTTP transport suitable for Webrick;
- [ ] `RunwireRuntimeAdapter` uses existing Webrick runtime abstractions;
- [ ] HTTP/1.1 and HTTP/2 normalize to the same Webrick application semantics;
- [ ] Workerman/SAPI/Swoole/RoadRunner adapters remain available;
- [ ] Runwire remains optional for standalone Webrick installations;
- [ ] request bodies/responses/files stream without unnecessary whole-message buffering;
- [ ] backpressure and cancellation are correctly propagated;
- [ ] request begin/end semantics guarantee exactly-once cleanup;
- [ ] sequential keep-alive and interleaved HTTP/2 request isolation is proven;
- [ ] malformed framing never becomes an ordinary routed request;
- [ ] trusted static-file fast path preserves Pathwise/public-root and Webrick HTTP semantics;
- [ ] worker drain/recycling does not become the request-isolation mechanism;
- [ ] Foundation bridge passes persistent/Fiber/isolation tests;
- [ ] Infbyte end-to-end persistent-runtime acceptance passes;
- [ ] direct Runwire+Webrick benchmark evidence is recorded;
- [ ] Foundation 3 can use Webrick over released Runwire 1.0 as its native HTTP path.

---

# 29. Non-goals

Do not add to Webrick as part of this pass:

- an event loop;
- socket server;
- HTTP/2 frame/HPACK/flow-control implementation;
- `pcntl`/`posix` supervision;
- process command runner;
- arbitrary shell execution;
- queue worker pool;
- Pathwise storage implementation;
- Foundation release/process registry;
- Laravel-style application/container clone sandbox;
- Laravel-specific warm/flush/reset listeners;
- host-specific shared cache/table systems;
- replacement of existing runtime adapters.

Runwire owns generic server/runtime mechanics; Webrick owns application-facing HTTP semantics; Foundation owns application lifecycle/state policy; Infbyte proves the integrated behavior.