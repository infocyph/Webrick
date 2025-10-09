# Deployments

Recipes for running Webrick in production across popular stacks. Everything here respects Webrick’s own middleware behaviors.

## What’s covered
- **Nginx / Apache** — Front‑controller rules, proxy headers, buffering, compression.
- **Containers / K8s** — Minimal images, health checks, OPcache/FPM tips.
- **Serverless / Edge** — Cache warm‑up, streaming caveats, signed URL pass‑through.
- **Troubleshooting** — Real‑world issues and quick fixes.

## Golden rules
- Choose **one source of compression** (proxy *or* app).
- Preserve **query strings** for signed/temporary URLs.
- Disable proxy buffering for streaming endpoints.
- Pre‑warm **route caches** in CI before shipping.

## Quick links
- 👉 [Nginx](./nginx.md) · 👉 [Apache](./apache.md) · 👉 [Containers](./containers.md) · 👉 [Kubernetes](./kubernetes.md) · 👉 [Serverless](./serverless.md) · 👉 [Troubleshooting](./troubleshooting.md)

## Example: Nginx front controller

```nginx
location / {
  try_files $uri /index.php?$query_string;
}
```

```{toctree}
:maxdepth: 2
:hidden:
:caption: Deployments

overview
nginx
apache
nginx-apache-fpm
containers
containers-and-ci
php-fpm-tuning
route-cache-warmup
kubernetes
serverless
vercel-and-serverless
troubleshooting
```
