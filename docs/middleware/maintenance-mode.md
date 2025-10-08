# Maintenance Mode

Gracefully take the app offline for deployments, migrations, or incident response. This middleware short-circuits requests with a **503 Service Unavailable** (and optional `Retry-After`) while allowing safe-lists and health checks.

---

## What it does

* Returns **503 Service Unavailable** for all requests when enabled
* Optionally sends `Retry-After: <seconds|HTTP-date>`
* Allows **bypasses** (IPs, CIDRs, header tokens, or cookies) for admins/health checks
* Can render a friendly HTML/JSON body based on `Accept` (or a custom template)
* Runs **early** so expensive work is avoided

---

## Wiring

Place it near the top of **pre-global** so it triggers before other heavy middleware:

```php
$preGlobal = [
  \Infocyph\Webrick\Middleware\GatewayHardeningMiddleware::class,
  \Infocyph\Webrick\Middleware\TelemetryMiddleware::class,
  new \Infocyph\Webrick\Middleware\MaintenanceModeMiddleware(
    enabled: (bool)($_ENV['WEBRICK_MAINTENANCE'] ?? false),
    retryAfter: (int)($_ENV['WEBRICK_MAINTENANCE_RETRY'] ?? 0),
    allow: [
      'ips'    => ['127.0.0.1', '::1'],                 // or CIDRs: '10.0.0.0/8'
      'header' => 'X-Maint-Bypass: your-secret-token',  // header:value exact match
      'cookie' => 'bypass=1',                           // simple sentinel cookie
      'paths'  => ['/health', '/metrics'],              // always allowed
    ],
    renderer: null // or callable(Request $r): Response
  ),
  // ...other pre-globals...
];
```

*(Adapt option names to your implementation.)*

---

## Enable/disable

Common toggles:

* **Environment variable**: set `WEBRICK_MAINTENANCE=true` during deploy window.
* **Flag file**: if the middleware supports it, enable when a file exists, e.g., `storage/maintenance.flag`.
* **Runtime hook**: expose an admin-only endpoint to toggle (protect this heavily).

**Retry-After**:

```bash
# e.g., expect to be back in 5 minutes
export WEBRICK_MAINTENANCE_RETRY=300
```

---

## Bypasses (safe-lists)

Choose one or more strategies:

* **IP/CIDR allowlist** – simplest for internal VPNs/jump hosts.
* **Header token** – `X-Maint-Bypass: <secret>` sent by trusted reverse proxies or test clients.
* **Cookie** – set once via a protected endpoint for admin browsers.
* **Path allowlist** – keep `/health` and `/metrics` green for load balancers and monitors.

> Always validate the source of bypass headers—only trust those set by your own proxy layer.

---

## Response shape

Default body for 503:

```json
{
  "error": {
    "code": "E_MAINTENANCE",
    "message": "Service temporarily unavailable. Please try again later."
  }
}
```

For browsers, you may prefer a branded HTML page. Provide a **renderer** callable:

```php
$renderer = function (\Infocyph\Webrick\Request\Request $r): \Infocyph\Webrick\Response\Response {
  $html = '<!doctype html><title>We’ll be back</title><h1>Maintenance</h1><p>Please try again soon.</p>';
  return \Infocyph\Webrick\Response\Response::create($html, 503, [
    'Content-Type' => 'text/html; charset=UTF-8'
  ]);
};
```

Pass `$renderer` into the middleware constructor.

---

## Ordering with other middleware

Recommended order near the top:

1. **Gateway Hardening** (basic sanity)
2. **Telemetry** (so 503s still get request IDs/trace)
3. **Maintenance Mode** ← here
4. **Request Limits / Throttle / …** (don’t matter when maintenance is on)

---

## Health checks & monitoring

* Keep `/health` and `/metrics` **outside** maintenance to prevent false alarms.
* Alternatively, let health checks pass based on internal source IP and still return **200**.

---

## Deploy pattern

1. Enable maintenance.
2. Drain traffic at the load balancer (optional).
3. Run migrations/builds.
4. Warm route caches & opcache.
5. Disable maintenance.
6. Smoke test via bypass, then open the gates.

Automate this in your CI/CD scripts with the env var or flag file.

---

## Testing

```bash
# with maintenance on
curl -i http://127.0.0.1:8000/
# expect: 503 + Retry-After (if set)

# bypass via header
curl -i -H "X-Maint-Bypass: your-secret-token" http://127.0.0.1:8000/
# expect: normal 200
```

---

## Troubleshooting

| Symptom                    | Cause                   | Fix                                                          |
| -------------------------- | ----------------------- | ------------------------------------------------------------ |
| Admins can’t bypass        | Wrong header/cookie/key | Confirm proxy sets header; check case-sensitivity and spaces |
| Health checks fail         | Path not allowlisted    | Add `/health` (and `/metrics`) to allowed paths              |
| Users see cached pages     | CDN/browser caching 503 | Add `Cache-Control: no-store` on maintenance responses       |
| 503 persists after disable | Stale env/config        | Reload PHP-FPM or clear opcache/config cache                 |

---

## Checklist

* [ ] Toggle via env var or flag file
* [ ] Add **Retry-After** to guide clients/browsers
* [ ] Safe-list IPs/paths for health checks & admins
* [ ] Render a branded HTML page for UX
* [ ] Place early in **pre-global** and keep responses non-cacheable
