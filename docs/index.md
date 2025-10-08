# Webrick – PHP Router

A fast, modern PHP router with a clean developer experience and production-grade features—routing primitives, powerful middleware pipeline (pre & post), content negotiation, compression, cache validators/ETags, signed & temporary URLs, throttling, cookie encryption, streaming, route cache warmups, attribute-based routes, and multi-domain grouping.

:::{admonition} TL;DR
:class: tip

* **Use it now:** `composer require infocyph/webrick`
* **Targets:** PHP 8.4+
* **Design goals:** low-latency, low-overhead, PSR ethos, practical ergonomics
  :::

---

## Why Webrick?

* **Routing, your way**: simple closures, controller methods, REST-y resource routes, attribute routes, grouped prefixes, and domain-scoped routes.
* **Production-first**: sharded or single-file (fused) route cache, pre/post global middleware, cache validators (304/412), robust compression (zstd/br/gzip), and strict throttling headers.
* **Batteries included**: signed/temporary URL helpers, automatic content negotiation, streaming responses, convenient JSON/text helpers, and an auto emitter.
* **Ergonomic & explicit**: predictable registration flow, clear middleware boundaries, and URL services you can bind once and reuse everywhere.

---

## Quick peek

```php
use Infocyph\Webrick\Router\Facade\Router as Route;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

// Basics
Route::get('/ping', fn () => 'pong', 'ping');

// JSON + params
Route::get('/hello/{name}', fn (Request $r, $name) => Response::json(['hello' => $name]));

// Signed URLs (see Guides → URLs)
Route::get('/secure/{id:int}', fn (Request $r, int $id) => Response::json(['ok'=>true,'id'=>$id]),
  ['as' => 'secure.show', 'middleware' => ['verifySignedUrl','throttle:2,1']]
);
```

Minimal front controller:

```php
use Infocyph\Webrick\Router\Kernel\RouterKernel;
use Infocyph\Webrick\Response\Emitter\AutoEmitter;

$kernel = RouterKernel::bootWithRegistrar(
  log: new Psr\Log\NullLogger(),
  matcher: Infocyph\Webrick\Router\Matching\ShardedMatcher::make(),
  register: static function ($routes) { require __DIR__.'/routes.php'; },
  routeCache: __DIR__.'/.route-cache',
  preGlobal:  [/* gateway hardening, limits, throttle, validators, ... */],
  postGlobal: [/* compression, CORS/policies, vary accumulator, ... */],
  bindUrlServices: static function ($routes) {
    Infocyph\Webrick\Response\Response::bindUrlServices($routes, 'your-sign-key', 900);
  },
  registrarOptions: ['exposeUrlServices'=>true, 'autoSlashRedirect'=>false],
);

(new AutoEmitter())->emit($kernel->handle());
```

---

## Architecture at a glance

```
┌───────── Client ─────────┐
            │
            ▼
┌────── HTTP Request ──────┐
            │
            ▼
┌── Pre-Global Middleware ─┐  (hardening, limits, throttle, cookies, negotiation, cache validators)
            │
            ▼
┌────────── Router ────────┐  (matcher → route → controller/handler)
            │
            ▼
┌─ Post-Global Middleware ─┐  (compression, CORS/policies, vary accumulation, response linting in dev)
            │
            ▼
┌───── Auto Emitter ───────┐  (sensible emission for streamed/static bodies)
            │
            ▼
┌──────── HTTP Response ───┐
```

* **Pre-global** runs **before** your controllers (security & request-shaping).
* **Post-global** runs **after** controllers (response shaping & delivery).
* **Matchers**: choose sharded (directory cache; great for large apps) or fused (single optimized PHP file).

---

## Feature highlights

* **Routes**: closures, controller methods, resources, attributes, groups, nested groups, and domain-scoped routes.
* **Signed & Temporary URLs**: bind URL services once and generate relative/absolute signed links with TTLs.
* **Content negotiation**: `Response::auto($request, $data)` picks JSON/text/XML sanely.
* **Compression**: zstd, Brotli, gzip (and optional deflate) with safe ETag strategies.
* **Cache validators**: `ETag`/`Last-Modified` handling with proper 304/412 outcomes.
* **Throttling**: `429`, `Retry-After`, `X-RateLimit-*` and (optionally) `RateLimit-*`.
* **Cookies**: optional encryption/decryption middleware.
* **Streaming**: yield chunks without buffering the world.
* **Route cache**: warm up ahead-of-time (AOT) or at boot; pick sharded/fused.
* **Utilities**: friendly helpers for redirects, attachments, named URLs, and more.

---

## Compatibility & conventions

* **PHP**: 8.4+ recommended.
* **Style**: PSR-12 guidelines.
* **Middleware**: PSR-15-inspired (Webrick’s concrete classes fit the same mental model).
* **Responses/Requests**: ergonomic Webrick types designed for low overhead while staying familiar.

---

## Production checklist

* [ ] Enable **route cache** (sharded or fused) and bake it in CI.
* [ ] Turn on **compression** and choose your ETag strategy (default strong recompute is safe).
* [ ] Wire **cache validators** to let proxies and clients short-circuit.
* [ ] Apply **throttle** & **request limits** on public endpoints.
* [ ] Configure **CORS & policies** explicitly.
* [ ] Bind **URL services** (sign key + TTL) once at boot.
* [ ] Prefer **streaming** for long-running or large responses.

---

## Get started

```bash
composer require infocyph/webrick
```

Then jump to **Getting Started → Quick Start** to wire your front controller, or browse **Guides** to explore routing styles, groups/domains, URLs, streaming, and more.

---

## Project navigation

```{toctree}
:maxdepth: 2
:hidden:

getting-started/index
guides/index
middleware/index
deployments/index
reference/index
changelog
contributing
```

* **Getting Started** – install, quickstart, first routes
* **Guides** – everyday tasks (routing, requests, responses, URLs, groups/domains, attributes, streaming, negotiation, throttling, cookies)
* **Middleware** – pre/post globals, knobs, headers, examples
* **Deployments** – route cache warmups, Nginx→Apache→FPM, tuning, containers, CI, serverless notes
* **Reference** – stable APIs: Router/Registrar, Matcher, Request/Response, Route Cache, Enums
* **Changelog** – releases & breaking changes
* **Contributing** – PSR-12, tests, performance & security focus

