love it — let’s consolidate the stack into a few “by-type” bundles without losing any behavior. here’s a crisp plan before we touch code.

# goals

* fewer middlewares to think about
* keep hot paths cheap
* preserve route-level overrides (attributes) and your Vary accumulator wiring
* provide a smooth deprecation path

# new bundles (and what they replace)

1. **GatewayHardeningMiddleware**

    * merges: `TrustProxiesMiddleware`, `HttpsEnforceMiddleware`, `HopByHopStripMiddleware`, `RedirectGuardMiddleware`
    * job: trust proxy cidrs + header mask, validate Host, enforce HTTPS (308), strip hop-by-hop (req+resp), block open redirects (allow-list)
    * notes: keep proxy setup (Request::setTrustedProxies) and host regex precompile as “once per worker” just like today.

2. **RequestLimitsMiddleware**

    * merges: `ValidateHeaderSizeMiddleware`, `ValidatePostSizeMiddleware`
    * job: fast 431/413 guards right up front
    * notes: run **before** anything that parses/reads the body.

3. **FormSupportMiddleware**

    * merges: `GuardEmptyPost`, `MethodOverrideMiddleware`
    * job: skip heavy form paths when certainly empty; apply `_method`/header override **before routing**
    * notes: `GuardEmptyPost`’s “fast pass” stays intact; no extra cost on non-POSTs.

4. **InputSanitizerMiddleware**

    * merges: `TrimStringsMiddleware`, `ConvertEmptyStringsToNullMiddleware`
    * job: trim + normalize empty strings in query/body/uploads
    * notes: JSON bodies untouched as today. Safe to run before CSRF.

5. **NegotiationMiddleware**

    * merges: `ContentNegotiationMiddleware`, `CharsetAttachMiddleware` (drop the latter’s separate class)
    * job: content-type + charset negotiation (with route `Produces`), stash `negotiated.*` attrs, set `Content-Type` if missing, register Vary: Accept (+ Accept-Charset only when it affects octets), **no charset on JSON**
    * notes: keeps your existing rules; CharsetAttach becomes a no-op folded in here.

6. **LocaleMiddleware**

    * keeps: `LocaleNegotiationMiddleware` logic, but it can be merged into NegotiationMiddleware **optionally**.
    * recommendation: keep this separate unless you want one mega-negotiator. It’s cheap and conceptually clear.
    * job: pick locale, set request `locale`, emit `Content-Language`, Vary: Accept-Language.

7. **CacheValidatorsMiddleware**

    * merges: `ConditionalMiddleware` + `ETagMiddleware`
    * job:

        * pre: evaluate If-None-Match / If-Modified-Since / Range using the provided entity meta closure; short-circuit 304/412; drop stale `Range`
        * post: if controller forgot, add strong ETag from body (seekable) using chunked hashing (your `Utils::etagFromStream`)
    * notes: keeps the closure model for cheap entity lookup; avoids double hashing.

8. **CorsAndPoliciesMiddleware**

    * merges: `CorsMiddleware`, `SecurityHeadersMiddleware`, `ContentSecurityPolicyMiddleware`, `ClientHintMiddleware`, `NelMiddleware`
    * job: handle preflight fully in-memory; add CORS headers on all responses (respect creds rule & Vary: Origin), then attach security headers (HSTS via `SecurityHeaders::tight` options), CSP, Accept-CH, NEL/Report-To.
    * notes: one config object with subsections (`cors`, `security`, `csp`, `clientHints`, `nel`). Route attribute `Cors` still overrides the `cors` subsection.

9. **TelemetryMiddleware**

    * merges: `RequestLoggingMiddleware`, `ResponseTimeMiddleware`
    * job: single timer; log combined entry; add `X-Response-Time` + `Server-Timing: app;dur=…`
    * notes: no double timers; you can later plug more spans (DB, view) if you want.

10. **Keep as standalones**

* `CompressionMiddleware` (transforms the entity; keep independent)
* `CookieEncryptionMiddleware` (decrypt early, re-encrypt late)
* `ThrottleMiddleware` (stateful; clean to keep separate)
* `CsrfMiddleware` (security boundary; keep separate)
* `MaintenanceModeMiddleware` (single-purpose)
* `VaryAccumulatorMiddleware` (remains the final combiner)
* `ResponseLinterMiddleware` (dev-only guard; keep separate)

# proposed pipeline order (top → bottom)

1. `MaintenanceModeMiddleware`
2. **RequestLimitsMiddleware**  (431/413)
3. **GatewayHardeningMiddleware** (trust proxies, HTTPS, Host, hop-by-hop)
4. **TelemetryMiddleware** (start timer)
5. `ThrottleMiddleware`
6. `CookieEncryptionMiddleware` (decrypt cookies)
7. **FormSupportMiddleware** (method override / fast-skip)
8. **InputSanitizerMiddleware**
9. **NegotiationMiddleware**
10. **LocaleMiddleware** (or fold into #9)
11. **CorsAndPoliciesMiddleware** (preflight here; policies applied always)
12. **CacheValidatorsMiddleware** (pre 304/412; drop stale Range)
13. controller / handler
14. `CookieEncryptionMiddleware` (encrypt Set-Cookie) — implicitly in the same class; it already wraps after next()
15. `CompressionMiddleware` (and weaken ETag, drop Content-MD5)
16. `VaryAccumulatorMiddleware`
17. `ResponseLinterMiddleware` (dev only)

# deprecation & BC plan

* keep old class names for one minor release:

    * make them **thin proxies** that construct and delegate to the new bundles (or to the relevant subsection), so DI bindings don’t break.
    * mark deprecated in phpdoc + trigger `@deprecated` notices in dev.
* config:

    * introduce a single `HttpPolicyConfig` (for #8) and `NegotiationConfig` (for #9) objects; map old constructor args to new config where possible.

# routing & attributes

* `Produces` (content types/charsets) → still honored by **NegotiationMiddleware**.
* `Cors` attribute → still honored by **CorsAndPoliciesMiddleware** (overrides only `cors` subsection).
* `CacheValidatorsMiddleware` keeps the same “meta closure” signature for conditional requests.

# open questions before we implement

* do you want **Locale** folded into **Negotiation** (#9) or kept separate as now?
* should `RedirectGuard` live inside **GatewayHardening** (as planned) or remain optional?
* any additional headers you want in **CorsAndPolicies** (e.g., `Access-Control-Expose-Headers`)?

if you’re good with this shape, I’ll draft the new classes + the compatibility shims and a sample container wiring so you can drop it in.
