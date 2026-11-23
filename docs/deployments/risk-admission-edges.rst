Risk admission behind NGINX, Caddy and CDNs
=============================================

This guide configures the **HTTP trust boundary** for Webrick's
:doc:`../middleware/risk-admission`. Webrick offers optional HTTP admission
decisions and handler bypass; it does **not** calculate JA4, score bot traffic,
verify CAPTCHA, manage accounts or issue trusted-device cookies. The complete
PHP risk engine is reserved for a future opt-in Foundation module. A plain
Webrick application continues to supply its own evaluator.

.. warning::

   A forwarded header, including `X-Verified-JA4`, is **not trusted merely
   because of its name**. Restrict the origin to authenticated/allowlisted proxy
   connections, overwrite client-supplied identity headers at the final trusted
   hop, and validate the actual peer before consuming the resulting values.
   Expose neither an alternate public origin port nor a direct PHP-FPM/Runwire
   listener that bypasses the proxy.

No-JA4 baseline (works without a fingerprint module)
-----------------------------------------------------

Deploy the following first. The final trusted edge can forward the client's
network address, but must **omit** JA4 unless a real TLS-fingerprinting component
has produced it. The application may use the verified network signal as *one*
risk input, never as an authentication credential. Handle absent JA4 as unknown,
not automatically malicious.

For a **single NGINX edge directly facing the client** and a loopback HTTP
application listener:

.. code-block:: nginx

   server {
       listen 443 ssl;
       server_name login.example.test;
       ssl_certificate     /etc/ssl/login.crt;
       ssl_certificate_key /etc/ssl/login.key;

       location / {
           proxy_pass http://127.0.0.1:8080;
           proxy_set_header Host $host;
           proxy_set_header X-Verified-Client-IP $remote_addr;
           proxy_set_header X-Verified-JA4 "";
           proxy_set_header X-Forwarded-For $remote_addr;
       }
   }

The certificate paths, domain and upstream are deployment placeholders;
configure TLS and binding appropriately. Here `$remote_addr` is the immediate
TLS peer because the example has **no upstream CDN**. If a CDN precedes NGINX,
configure `ngx_http_realip_module` with **only that CDN's verified IP ranges**
before deriving client IP; blindly accepting `X-Forwarded-For` or
`$proxy_add_x_forwarded_for` from the internet is not equivalent.

For a **single Caddy edge directly facing the client** and a loopback HTTP
application listener:

.. code-block:: text

   login.example.test {
       reverse_proxy 127.0.0.1:8080 {
           header_up X-Verified-Client-IP {remote_host}
           header_up -X-Verified-JA4
       }
   }

`header_up` overwrites a supplied upstream request-header value, and a
leading `-` removes an upstream header. `{remote_host}` is the directly
connected peer, **not** an IP recovered from an untrusted forwarding chain.
When Caddy itself is behind a CDN, configure its global
`servers { trusted_proxies ... }` with the CDN's real CIDRs and qualify
`{client_ip}` explicitly; do not treat arbitrary private ranges as trusted
unless your network architecture actually warrants it.

NGINX `proxy_set_header` applies to **HTTP proxy_pass**. For a direct
PHP-FPM/FastCGI upstream, use the corresponding `fastcgi_param` setup and
ensure that the PHP request adapter receives only trusted, overwritten values.
Do not copy the HTTP reverse-proxy snippet into a FastCGI location unchanged.

Using a real JA4-capable edge
-----------------------------

JA4 is computed from the TLS ClientHello by a **capable CDN, gateway or
explicitly installed/verified server module**. Vanilla NGINX and Caddy
reverse-proxy configuration does not supply such a computed JA4 variable.
In particular, NGINX `$http_ssl_ja4` reads a client-provided `Ssl-Ja4`
**HTTP header**, not the TLS handshake; it must not be forwarded as an
attested fingerprint.

If the deployment includes a real JA4 producer, arrange for the producer to
send a separately authenticated signal to the final trusted gateway. That
gateway validates the producer and **replaces** any external
`X-Verified-JA4` value before forwarding to the restricted application
origin. Configure the exact variable/header mapping according to the
installed producer's documentation; there is deliberately **no universal
JA4 variable** in these example snippets.

The application's evaluator should validate the trusted peer, presence,
length and allowed syntax of the attested JA4 and client address, and use a
missing/invalid fingerprint as a documented *risk-data unavailable* case.
A single JA4 is shared by many legitimate browsers and can be imitated.
Never automatically treat it as a cryptographic device identifier.


Native gateway-to-risk evaluator bridge
---------------------------------------

Webrick's existing `GatewayHardeningMiddleware` can expose a **normalized
JA4 and ASN** to a host evaluator without making a new risk-scoring service
or forcing a dependency on Foundation. This feature is **disabled by default**
and only reads the configured headers when the socket peer matches the
gateway's configured `trustedProxyCidrs`:

.. code-block:: php

   use Infocyph\Webrick\Middleware\GatewayHardeningMiddleware;
   use Infocyph\Webrick\Middleware\RiskAdmissionMiddleware;
   use Infocyph\Webrick\Middleware\RiskDecision;
   use Infocyph\Webrick\Request\Request;

   $edge = new GatewayHardeningMiddleware(
       trustedProxyCidrs: ['127.0.0.1/32'],
       trustedClientIpHeaders: ['X-Verified-Client-IP'],
       trustedJa4Header: 'X-Verified-JA4',
       trustedAsnHeader: 'X-Verified-ASN',
       enforceHttps: false, // TLS terminates at the trusted local edge
   );

   $evaluator = static function (Request $request): RiskDecision {
       $ja4 = $request->getAttribute('risk_verified_ja4'); // ?string
       $asn = $request->getAttribute('risk_verified_asn'); // ?int
       $clientIp = $request->getAttribute('client_ip'); // ?string

       // Host-owned risk scoring remains independent of Webrick.
       // Missing signals must not be confused with verified ones.
       return RiskDecision::allow();
   };

   $risk = new RiskAdmissionMiddleware($evaluator);

Place `$edge` in front of `$risk` on selected protected routes,
or in the global pre-route pipeline. The IP configuration above is valid
**only when your NGINX/Caddy HTTP gateway actually connects from loopback**;
replace it with a narrowly scoped CIDR belonging to your own trusted edge
for any other topology. The edge must overwrite the configured headers,
including deleting the JA4/ASN headers when their provenance is unknown.
Do not trust arbitrary `X-Forwarded-For` chains or a user's HTTP
fingerprint claim.

A valid, present `X-Verified-JA4` is exposed as
`risk_verified_ja4`; a valid decimal or `AS`-prefixed 32-bit ASN
is exposed as `risk_verified_asn`. Missing signals become `null`.
Duplicate, comma-joined, malformed or oversized values sent **from the
trusted hop** are rejected before the protected handler. Values from an
untrusted socket peer are ignored. These attributes are scoped to the
immutable request and never persisted in Webrick or interpreted as
authentication credentials. Webrick does not decide browser consistency or
trust levels; these belong to the host (later, opt-in Foundation).

App-side admission and storage safety
-------------------------------------

Register ``RiskAdmissionMiddleware``
only on the host's protected routes (see
:doc:`../middleware/risk-admission`). The evaluator returns a
`RiskDecision`; `allow` continues to the handler, `challenge` returns
429, `deny` returns 403 and `unavailable` returns 503 by default.
Shadow mode forwards every valid verdict and **must not** consume proof,
write counters, spend clearance or mutate device state.

.. warning::

   An application may inject **any CacheLayer adapter**, but effective
   distributed credential-stuffing defense requires shared, authoritative
   coordination with qualified atomic operations, expiry and failure semantics
   across all PHP workers/hosts. Process-memory, APCu, local-only, tiered and
   non-atomic stores cannot claim distributed one-use proof redemption or
   global password-hash capacity. Never turn a cache miss, backend outage or
   unverifiable atomic claim into authorization; fail closed for proof/lease
   operations. CacheLayer itself is not a rate-limiting policy or a login engine.

Run password verification in a deployment-isolated, bounded CPU worker pool.
A Runwire Fiber or local coroutine semaphore does not turn blocking
`password_verify()` into nonblocking work or ensure a global capacity ceiling.
Always keep the HTTP admission decision **before** expensive password hashing.

Operator validation checklist
-----------------------------

* Direct requests to the origin must be impossible or denied, including HTTP/3
  and alternative service bindings.
* A client sending `X-Verified-Client-IP`, `X-Verified-JA4` or
  `X-Forwarded-For` cannot change the trusted identity presented downstream.
* The no-JA4 path works correctly before optional fingerprint integration.
* A known-dead provider, missing authoritative atomic store, malformed proof,
  replayed proof and exhausted hash pool never execute credential verification.
* Authentication responses are not cached; verify `Cache-Control: no-store`
  and preserve consistent HEAD/HTTP semantics.
* The final proxy configuration is validated with the installed server's
  own configuration checker, and CDN IP ranges/keys are rotated operationally.

References: `NGINX proxy_set_header
<https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_set_header>`_,
`NGINX real IP
<https://nginx.org/en/docs/http/ngx_http_realip_module.html>`_,
`Caddy reverse_proxy
<https://caddyserver.com/docs/caddyfile/directives/reverse_proxy>`_,
`Caddy trusted_proxies
<https://caddyserver.com/docs/caddyfile/options#trusted_proxies>`_.
