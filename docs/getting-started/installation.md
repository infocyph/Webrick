# Installation

This page gets you from zero to a working Webrick install. The router targets **PHP 8.4+** and ships as a Composer library.

:::{admonition} Requirements
:class: tip

* **PHP:** 8.4+
* **Extensions:** `mbstring`, `json`, `zlib` (optionally `brotli`/`zstd` if you want those codecs)
* **Composer:** latest stable
* **Web server (prod):** Nginx → PHP-FPM (Apache optional in front)
:::

---

## 1) Install via Composer

```bash
composer require infocyph/webrick
```

This will install the core package and its dependencies (`infocyph/arraykit`, `infocyph/intermix`).

---

## 2) Minimal project layout

You can choose any layout; just keep the **route cache** path writable by your deploy user.

```
your-app/
├─ public/
│  └─ index.php              # front controller
├─ routes/
│  └─ web.php                # your routes (or use attribute routes)
├─ src/                      # controllers, services
├─ var/
│  └─ cache/routes/          # route cache (sharded dir) or a single fused file
└─ vendor/
```

---

## 3) Environment keys

Set secrets via environment variables or your config layer:

- **`WEBRICK_SIGN_KEY`**: secret used for signed/temporary URLs. Any reasonably long random string is fine; rotate if leaked.
- **`WEBRICK_SIGN_TTL`**: default TTL (seconds) for temporary URLs, e.g., `900`.
- **`WEBRICK_COOKIE_KEYS`** (optional): a comma‑separated list of **32‑byte raw keys** for cookie encryption (AES‑256‑GCM). The **index** in the list is the active Key ID (KID) you pass to the middleware.

Example `.env`:

```env
WEBRICK_SIGN_KEY="base64:KJ9r...replace_me...kQ="
WEBRICK_SIGN_TTL=900
WEBRICK_COOKIE_KEYS="hex:001122... (32 bytes),hex:8899aa... (32 bytes)"
```

> Cookie encryption is optional. If you enable it, supply at least one 32‑byte key; add more for rotation.

---

## 4) Web server setup (production)

Use your preferred stack. A typical Nginx → PHP‑FPM setup serves static assets directly and forwards everything else to the front controller.

**Key points**

- Ensure your front controller (e.g., `public/index.php`) receives all non‑existing paths.
- Do **not** enable double compression at the edge if you use Webrick’s **CompressionMiddleware**.
- Make the **route cache** path (`var/cache/routes` or fused file) writable during deploys.

See **Deployments → Nginx/Apache/PHP‑FPM** for copy‑paste configs and tuning.
