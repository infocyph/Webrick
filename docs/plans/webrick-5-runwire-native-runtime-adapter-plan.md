# Webrick 5 — Runwire Native Runtime Adapter Plan

## Status

Target: **Webrick 5.x + Runwire 1.0**

Branch: `webrick-5/runwire-runtime-adapter`

Primary consumer: **Foundation 3**

Canonical lower-runtime plan: `infocyph/Runwire` → `docs/plans/runwire-1.0-foundation-3-launch-plan.md`

Priority:

> HTTP correctness → persistent-runtime isolation → streaming/backpressure → security bounds → low-copy adaptation → performance → compatibility

This plan adds Runwire as Webrick's native Infocyph persistent-server transport while preserving Webrick as the sole owner of application-facing HTTP request/response/routing semantics.

---

# 1. Ownership boundary

## Runwire owns

- master/worker process supervision;
- `pcntl` / `posix` process mechanics;
- event loop;
- TCP/Unix/TLS listeners;
- connection lifecycle;
- socket input/output buffers and backpressure;
- HTTP/1.1 wire framing/parsing/serialization;
- transport-level header/body/time/connection hard bounds;
- client disconnect detection;
- transport response writing.

## Webrick owns

- `Request` / `Response` semantics;
- `RuntimeAdapterInterface` and Webrick runtime adaptation;
- routing and compiled route artifacts;
- middleware;
- content negotiation;
- cookies;
- application HTTP security policy;
- application request limits above Runwire's transport hard limits;
- uploaded-file request representation;
- error rendering;
- range/HEAD/application response semantics;
- runtime request context passed to Foundation/application code.

## Foundation owns

- selecting Runwire as its native server;
- app/release configuration;
- DI/InterMix graph;
- fresh execution scope per HTTP request;
- authentication/session/database/application state;
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

These remain legitimate deployment/interop choices for standalone Webrick consumers.

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

---

# 3. Dependency policy

Webrick should remain usable without Runwire.

Recommended Composer policy:

- keep `infocyph/runwire` out of Webrick's unconditional production `require` if the adapter can be isolated cleanly;
- use `require-dev: infocyph/runwire:^1.0` for adapter tests/reference integration once released;
- add Composer `suggest` text for Runwire native server integration;
- while Runwire 1.0 is under development, use the development branch only in integration CI/working branches;
- final Foundation 3 release must consume a released Runwire 1.0.

If PHP class signatures would force Runwire classes to be loaded merely by loading Webrick core, redesign the adapter boundary so Runwire remains optional rather than making every Webrick installation install a server runtime.

---

# 4. Add `RunwireRuntimeAdapter`

Preferred location:

```text
src/Runtime/Http/RunwireRuntimeAdapter.php
```

It must implement Webrick's existing:

```text
RuntimeAdapterInterface
```

Do not create a parallel HTTP-runtime abstraction just for Runwire.

Where a native request wrapper materially reduces copying, add a narrowly scoped adapter value such as:

```text
src/Runtime/Http/RunwireNativeRequest.php
```

Only add it if existing `TransportRequestFactory` / `RuntimeRequestContext` cannot adapt Runwire efficiently and correctly.

---

# 5. Native request adaptation

The Runwire adapter should consume a stable Runwire HTTP transport contract, not internal parser/socket classes.

Required request information:

- method;
- request target;
- path/query;
- protocol version;
- headers with duplicate/multi-value semantics preserved;
- request body stream;
- remote/local address metadata;
- TLS metadata where intentionally exposed;
- connection/request identity only when useful and non-authoritative;
- upload/body streaming information required to construct Webrick request semantics.

Requirements:

- no reconstruction through PHP superglobals;
- no unnecessary PSR-7 conversion hop;
- no full body copy merely to cross the adapter;
- no header flattening that changes HTTP semantics;
- no Runwire connection/parser object leaked into ordinary Webrick application handlers;
- Webrick request-scoped mutable data must not be retained by Runwire worker-global objects.

---

# 6. Request body streaming

A persistent native server makes body handling especially important.

The adapter must preserve Webrick body semantics while allowing Runwire to enforce transport backpressure.

Required behavior:

```text
socket
  -> Runwire bounded receive buffer
  -> Runwire request body stream
  -> Webrick BodyStream / Request
  -> application consumer
```

Do not default to:

```text
socket -> concatenate full body -> copy -> Webrick string body
```

for arbitrary/large requests.

Requirements:

- bounded request body buffering;
- correct partial reads;
- body limit failures map consistently;
- unread request bodies are drained or connection-closed according to safe protocol policy before reusing keep-alive connection;
- client disconnect/cancellation is surfaced without contaminating the next request;
- request body state never survives into another request on the same persistent connection.

---

# 7. Uploaded files

Webrick continues to own request-level uploaded-file representation. Pathwise continues to own storage/upload trust-boundary processing once Foundation/application accepts an upload.

Runwire only provides transport/body bytes and multipart-capable streaming primitives if required by the selected Webrick request parser architecture.

Do not move Pathwise upload validation/scanning/storage policy into Runwire or Webrick runtime adapter code.

If multipart parsing remains Webrick-owned, ensure Runwire's body-stream contract allows Webrick to parse incrementally without buffering the complete body.

---

# 8. Response adaptation and backpressure

Target flow:

```text
Webrick Response
      ↓
RunwireRuntimeAdapter
      ↓
Runwire response writer
      ↓
bounded Runwire connection send buffer
      ↓
socket
```

Requirements:

- preserve status code/reason semantics;
- preserve duplicate response headers such as `Set-Cookie`;
- preserve Webrick HEAD semantics;
- stream `BodyStream`, file/range bodies and iterable/chunked bodies incrementally;
- avoid materializing complete streaming/file responses;
- propagate send-side backpressure so fast producers cannot cause unbounded memory growth;
- stop producing when the client disconnects;
- response completion must be explicit before request scope cleanup where the application contract requires it;
- after-response hooks must run at the correct semantic boundary, not merely when bytes are queued in memory.

Webrick must define what "response complete" means for its runtime contract and map Runwire's transport completion state appropriately.

---

# 9. Keep-alive and request isolation

One Runwire TCP connection may carry many HTTP requests. Webrick must treat every request as independent application execution.

Required invariant:

```text
Runwire connection
   ├─ request A -> new Webrick RuntimeRequestContext -> Foundation execution A -> cleanup
   ├─ request B -> new Webrick RuntimeRequestContext -> Foundation execution B -> cleanup
   └─ request C -> new Webrick RuntimeRequestContext -> Foundation execution C -> cleanup
```

Never cache in connection/adapter state:

- current user/principal;
- session object;
- request input;
- route parameters;
- DB connection/transaction;
- per-request middleware state;
- validation result;
- response headers/body;
- exception/error state.

Add sequential and interleaved persistent-request isolation tests.

---

# 10. Runtime capabilities

Extend/use Webrick `RuntimeCapabilities` rather than creating Runwire-specific capability checks throughout the codebase.

Map only HTTP-behavior-relevant capabilities such as:

- streaming response support;
- after-response completion semantics;
- native file/body streaming support;
- client disconnect visibility;
- persistent worker behavior.

Generic capabilities such as `pcntl_fork`, POSIX identity or event backend remain Runwire concerns and should not be duplicated in Webrick.

---

# 11. HTTP limits split

Runwire transport hard bounds and Webrick application limits must compose rather than duplicate ambiguously.

Examples:

```text
Runwire
  max request-line bytes
  max header-line bytes
  max aggregate header bytes
  hard body framing ceiling
  slow-header/body timeout
  connection buffer ceiling

Webrick
  route/application body policy
  content-type policy
  form/input parameter policy
  application upload policy
  middleware request limits
```

Rules:

- Webrick cannot configure an application limit above a lower Runwire hard transport ceiling and expect it to work;
- Foundation should validate incompatible configuration at startup;
- do not reparse raw HTTP in Webrick merely to enforce a transport rule Runwire already authoritatively enforced;
- security/error behavior should be documented clearly by layer.

---

# 12. Error mapping

Classify failures at the correct boundary.

Runwire transport/protocol failure examples:

- malformed request framing;
- header ceiling exceeded;
- body framing conflict;
- request timeout before valid Webrick request exists;
- connection reset.

These generally should not enter normal Webrick application routing.

Webrick failures:

- route/method outcome;
- middleware rejection;
- controller/handler failure;
- content negotiation/application validation;
- application body policy after transport accepted framing.

The adapter should translate only failures for which a valid Webrick HTTP response can safely be produced. Do not turn a corrupt HTTP stream into an ordinary application request.

---

# 13. Client cancellation

Expose enough cancellation/disconnect state to avoid wasted application work and unsafe after-response assumptions.

Requirements:

- adapter can observe connection/request cancellation where Runwire exposes it;
- streaming response stops promptly after client disconnect;
- cancellation does not become an exception leaked into the next keep-alive request;
- Foundation request-scope cleanup still runs exactly once;
- transactional/application side effects are not automatically rolled back merely because the client disconnected—application policy remains authoritative.

Do not introduce a Webrick-wide async framework solely for cancellation.

---

# 14. Runtime lifecycle hooks

Webrick should not own Runwire worker supervision.

It may expose or reuse narrow hooks needed for application runtime lifecycle:

```text
worker/application boot callback
request begin
request end
worker/application shutdown callback
```

Foundation maps these to its application lifecycle.

Runwire owns:

- worker spawn/restart/reload;
- signal handling;
- process status;
- listener lifecycle.

Webrick owns only HTTP runtime adaptation inside an already-running worker.

---

# 15. Workerman adapter coexistence

The existing Workerman adapter remains important for compatibility and as a comparison baseline.

Do not rewrite `WorkermanRuntimeAdapter` to proxy through Runwire.

They are separate runtime adapters:

```text
Workerman transport -> WorkermanRuntimeAdapter -> Webrick
Runwire transport   -> RunwireRuntimeAdapter   -> Webrick
```

This provides a clean benchmark and semantic-parity surface.

---

# 16. Foundation bridge

Foundation's native production flow becomes:

```text
Runwire HTTP server
   ↓
Webrick RunwireRuntimeAdapter
   ↓
Foundation request execution adapter/scope
   ↓
Webrick compiled kernel
   ↓
Webrick Response
   ↓
Runwire writer
```

Webrick must not depend on Foundation to make the Runwire adapter work; standalone Webrick applications must be able to supply their own runtime handler.

---

# 17. Tests

Add focused adapter tests for:

- simple GET/POST;
- query strings;
- duplicate request headers;
- duplicate response headers / Set-Cookie;
- known-length body;
- chunked body;
- streaming request body;
- large bounded request;
- body limit failure;
- keep-alive sequential requests;
- request state isolation;
- malformed transport never routed;
- HEAD response;
- 404/405 parity;
- streaming response;
- file/range response;
- client disconnect during body read;
- client disconnect during response write;
- backpressure with slow reader;
- exception/error response;
- request cleanup after error/cancellation;
- Foundation bridge fixture;
- no Runwire classes required on ordinary SAPI-only Webrick load when optional integration is absent.

---

# 18. Semantic parity matrix

For equivalent valid HTTP requests, compare at least:

```text
SAPI
Workerman
Runwire
```

and where CI supports them:

```text
Swoole/OpenSwoole
RoadRunner
```

Verify parity for:

- routing input;
- headers/cookies/query/form input;
- body access;
- response status/headers/body;
- HEAD;
- 404/405;
- middleware;
- exception mapping;
- streaming semantics where each runtime supports them.

Runtime-specific transport limitations can differ, but Webrick application semantics must remain intentional/documented.

---

# 19. Performance benchmarks

Do not merge the Runwire adapter into the existing Apache/FPM recovery benchmark and hide the runtime boundary.

Keep separate benchmarks:

1. Webrick kernel-only.
2. Existing Apache/FPM real HTTP.
3. Workerman + Webrick.
4. Runwire + Webrick.
5. Foundation + Webrick + Runwire full stack.

Use the same representative routes where possible.

Measure:

- requests/sec;
- p50/p95/p99;
- CPU;
- RSS;
- request allocations/copies where measurable;
- keep-alive throughput;
- streaming throughput;
- slow-client memory;
- error rate;
- performance before/after adapter changes.

No benchmark-only bypass of Webrick semantics.

---

# 20. Security acceptance

Release-blocking Webrick/Runwire integration properties:

- Runwire malformed framing cannot reach application routing as trusted request state;
- Webrick cannot accidentally bypass Runwire transport bounds;
- no unbounded adapter body/header copies;
- no state from one persistent request survives into the next;
- client disconnect cannot leave stale runtime request context;
- request/response header semantics are preserved without injection/flattening bugs;
- Pathwise remains upload/storage security owner;
- ReqShield remains validation owner;
- Webrick does not acquire process/shell execution APIs;
- Workerman/SAPI compatibility paths remain unaffected when Runwire integration is not selected.

---

# 21. Documentation

Document:

- Runwire as native Infocyph persistent server option;
- installation/optional dependency;
- minimal standalone Webrick + Runwire example;
- Foundation 3 native-server relationship;
- ownership boundary between Runwire and Webrick;
- streaming/backpressure expectations;
- transport hard bounds vs Webrick application limits;
- keep-alive/persistent-state rules;
- Workerman/Swoole/RoadRunner remain supported alternatives;
- deployment behind Nginx/HAProxy/reverse proxies;
- TLS termination choices.

---

# 22. Implementation order

```text
1. Freeze Runwire HTTP transport contract needed by adapters.
2. Add require-dev/reference integration.
3. Implement RunwireRuntimeAdapter with smallest possible translation layer.
4. Preserve body streaming and response backpressure.
5. Add request/response semantic parity tests.
6. Add persistent isolation/cancellation tests.
7. Add Foundation bridge tests.
8. Benchmark Runwire+Webrick against Workerman+Webrick and existing baselines.
9. Tune only measured adapter overhead.
10. Release adapter support coordinated with Runwire 1.0 / Foundation 3.
```

---

# 23. Completion gate

This plan closes only when:

- [ ] Runwire 1.0 exposes a stable HTTP transport boundary suitable for Webrick;
- [ ] `RunwireRuntimeAdapter` uses the existing Webrick runtime abstraction rather than creating another one;
- [ ] Workerman/SAPI/Swoole/RoadRunner adapters remain available;
- [ ] Runwire remains optional for standalone Webrick installations;
- [ ] valid HTTP semantics pass runtime parity tests;
- [ ] request bodies and responses stream without unnecessary whole-message buffering;
- [ ] backpressure/cancellation are correctly propagated;
- [ ] persistent request state isolation is proven;
- [ ] malformed/framing-invalid input is rejected below Webrick application routing;
- [ ] Foundation bridge passes repeated/keep-alive/Fiber-isolation tests;
- [ ] direct Runwire+Webrick benchmark evidence is recorded;
- [ ] Foundation 3 can use Webrick over released Runwire 1.0 as its native HTTP path.

---

# 24. Non-goals

Do not add to Webrick as part of this pass:

- a new event loop;
- socket server implementation;
- `pcntl`/`posix` supervision;
- process command runner;
- arbitrary shell execution;
- a queue worker pool;
- Pathwise upload/storage mechanics;
- Foundation release/process registry;
- HTTP/2 implementation merely to match a server feature checklist;
- replacement of existing runtime adapters.

Runwire owns generic server/runtime mechanics; Webrick remains the application-facing HTTP runtime.