# Nginx → Apache → PHP-FPM

Concrete, production-ready web-server configs for Webrick. Pick **Nginx → PHP-FPM** (most common) or **Apache → PHP-FPM**. Each recipe serves static assets directly and forwards everything else to `public/index.php`.

> Webrick handles compression, caching headers, and validators; disable double-work at the edge.

---

## 1) Nginx → PHP-FPM (recommended)

### Minimal, safe default

```nginx
# /etc/nginx/conf.d/webrick.conf
server {
  listen 80;
  server_name example.com;

  # 1) Webroot
  root /var/www/your-app/public;
  index index.php;

  # 2) Security & basics
  add_header X-Content-Type-Options nosniff always;
  add_header X-Frame-Options DENY always;
  add_header Referrer-Policy strict-origin-when-cross-origin always;

  # 3) Static files (served directly)
  location ~* \.(?:css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|map)$ {
    access_log off;
    expires 7d;
    add_header Cache-Control "public, immutable";
    try_files $uri =404;
  }

  # 4) Front controller for everything else
  location / {
    try_files $uri /index.php?$query_string;
  }

  # 5) PHP-FPM upstream
  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param HTTPS $https if_not_empty;

    # Match your PHP version/socket:
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;   # or fastcgi_pass 127.0.0.1:9000;

    # Reasonable timeouts (streams/long requests)
    fastcgi_read_timeout 120s;
    fastcgi_send_timeout 120s;

    # Disable gzip here; app handles compression
    gzip off;
  }

  # 6) Real IP from reverse proxies (adjust for your network/CDN)
  # set_real_ip_from  10.0.0.0/8;
  # real_ip_header    X-Forwarded-For;
  # real_ip_recursive on;
}
```

**Notes**

* `try_files $uri /index.php?$query_string;` is critical—no rewrites needed.
* Keep Nginx `gzip off` to avoid double compression when Webrick’s Compression middleware is on.
* If you serve very long streams/SSE, also set: `proxy_buffering off;` when proxying (not needed above since FPM).

### Optional: HTTPS + HSTS (with certbot)

```nginx
server {
  listen 443 ssl http2;
  server_name example.com;

  ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
  ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

  include /etc/nginx/snippets/ssl-params.conf;   # ciphers, protocols, stapling

  add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
  # ... same locations as the HTTP server block ...
}
server {
  listen 80;
  server_name example.com;
  return 301 https://$host$request_uri;
}
```

### Optional: Brotli at Nginx (off when app compresses)

If you prefer **edge compression**, disable app compression and enable Nginx Brotli:

```nginx
# global http {} scope
brotli on; brotli_comp_level 5;
brotli_types text/plain text/css application/javascript application/json application/xml image/svg+xml;
```


### Avoid Double Compression

**Critical**: If you enable Nginx compression, disable Webrick's CompressionMiddleware:
```nginx
# Option A: Compress at Nginx
gzip on;
gzip_comp_level 5;
gzip_types text/plain text/css application/javascript application/json;

# In your app: Remove CompressionMiddleware from $postGlobal
```

**OR**
```nginx
# Option B: Compress at app (recommended for precise ETag control)
gzip off;

# In your app: Keep CompressionMiddleware in $postGlobal
```

**Never do both** - results in corrupted responses and broken ETags.

> Do **one** place of compression (edge **or** app) to keep ETags correct.

---

## 2) Apache → PHP-FPM

Two ways: **a)** Proxy to PHP-FPM via `ProxyPassMatch`, or **b)** Use `SetHandler "proxy:unix:/path|fcgi://localhost"` for UNIX sockets. Below is a clean vhost using ProxyPassMatch.

### VirtualHost

```apache
# /etc/apache2/sites-available/webrick.conf
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /var/www/your-app/public

    # 1) Security headers (Apache side)
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    # 2) Don’t double-compress; let app do it
    SetEnv no-gzip 1
    # Or disable modules if globally enabled:
    # SetOutputFilter REMOVE_DEL "gzip"

    # 3) Serve static directly (mod_expires)
    <IfModule mod_expires.c>
        ExpiresActive On
        ExpiresByType text/css "access plus 7 days"
        ExpiresByType application/javascript "access plus 7 days"
        ExpiresByType image/svg+xml "access plus 7 days"
        ExpiresByType image/webp "access plus 7 days"
        ExpiresByType image/png "access plus 7 days"
        ExpiresByType image/jpeg "access plus 7 days"
        ExpiresByType font/woff2 "access plus 7 days"
    </IfModule>
    Header set Cache-Control "public, immutable"

    # 4) Front controller routing
    <Directory "/var/www/your-app/public">
        AllowOverride None
        Options -Indexes +FollowSymLinks
        Require all granted

        # Rewrite to index.php
        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule ^ index.php [QSA,L]
        </IfModule>
    </Directory>

    # 5) PHP-FPM via ProxyPassMatch (adjust socket/host)
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
    </FilesMatch>

    # Pass SCRIPT_FILENAME correctly
    ProxyPassMatch ^/(.*\.php(/.*)?)$ unix:/run/php/php8.4-fpm.sock|fcgi://localhost/var/www/your-app/public/$1

    # Timeouts for long requests/streams
    ProxyTimeout 120
    Timeout 120

    # Real IP from proxy/CDN (if behind one)
    # RemoteIPHeader X-Forwarded-For
    # RemoteIPTrustedProxy 10.0.0.0/8

    ErrorLog ${APACHE_LOG_DIR}/webrick_error.log
    CustomLog ${APACHE_LOG_DIR}/webrick_access.log combined
</VirtualHost>
```

Enable required modules:

```bash
a2enmod proxy proxy_fcgi headers rewrite expires remoteip
systemctl reload apache2
```

**.htaccess?** Not needed; we keep rewrites in the vhost for performance. If you must use `.htaccess`, mirror the rewrite block there and set `AllowOverride FileInfo`.

---

## 3) Shared best practices

### Permissions & ownership

* App directory owned by deploy user; **group** to web user (e.g., `www-data`).
* `var/` writable by FPM user: `chown -R deploy:www-data var && chmod -R g+w var`.

### Timeouts & buffering

* For **SSE/streaming**, ensure:

    * Nginx: `proxy_buffering off;` (if proxying), generous `fastcgi_read_timeout`.
    * Apache: avoid output filters buffering streams; leave compression to app.

### Proxy headers / HTTPS detection

* Ensure `HTTPS`/scheme is passed to PHP:

    * Nginx: `fastcgi_param HTTPS $https if_not_empty;`
    * Apache: `SetEnvIf X-Forwarded-Proto https HTTPS=on`
* If generating **absolute URLs**, configure your app’s base URL or trust proxy headers appropriately.

### CDN/Edge

* If a CDN fronts your app:

    * Either **let CDN compress** and disable app compression, **or** serve already-compressed from app and disable CDN compression.
    * Forward `If-*` headers for validators so **304**s can pass through.
    * Respect `Vary` headers (ensure CDN is not stripping them).

---

## 4) Quick diagnostics

* **PHP info route** (dev only):

  ```php
  Route::get('/__phpinfo', fn() => Response::create(phpinfo(), 200, ['Content-Type'=>'text/html']));
  ```

  Verify `$_SERVER` vars (SCRIPT_FILENAME, HTTPS, REMOTE_ADDR).

* **Headers check**:

  ```bash
  curl -I https://example.com/
  ```

  Confirm `Content-Encoding` appears **once** (if app compresses), `Vary` includes expected tokens.

* **Routing sanity**:

  ```bash
  curl -i http://example.com/does-not-exist
  ```

  Should hit your 404 handler (index.php route), not serve the directory.

---

## 5) Common pitfalls

| Symptom                        | Likely cause                | Fix                                                                          |
| ------------------------------ | --------------------------- | ---------------------------------------------------------------------------- |
| All routes 404                 | Wrong `try_files`/rewrite   | Use Nginx `try_files $uri /index.php?$query_string;` or Apache rewrite block |
| Double compression (garbled)   | Nginx/Apache gzipping + app | Turn off edge gzip/Brotli or app compression—pick one                        |
| Wrong host in absolute URLs    | Missing proxy scheme/host   | Pass `HTTPS` & `Host`; configure base URL or trust proxy headers             |
| “File download” shows PHP code | PHP-FPM handler not matched | Ensure `FilesMatch \.php$` / `location ~ \.php$` blocks are correct          |
| Slow first hit after deploy    | Cold OPcache                | Warm OPcache and **route cache** during deploy                               |

---

## 6) Copy-paste snippets

**Nginx include for PHP params** (use upstream distro defaults, e.g., `/etc/nginx/fastcgi_params`):

```nginx
fastcgi_param QUERY_STRING       $query_string;
fastcgi_param REQUEST_METHOD     $request_method;
fastcgi_param CONTENT_TYPE       $content_type;
fastcgi_param CONTENT_LENGTH     $content_length;

fastcgi_param SCRIPT_NAME        $fastcgi_script_name;
fastcgi_param REQUEST_URI        $request_uri;
fastcgi_param DOCUMENT_URI       $document_uri;
fastcgi_param DOCUMENT_ROOT      $document_root;
fastcgi_param SERVER_PROTOCOL    $server_protocol;

fastcgi_param HTTPS              $https if_not_empty;

fastcgi_param GATEWAY_INTERFACE  CGI/1.1;
fastcgi_param SERVER_SOFTWARE    nginx/$nginx_version;

fastcgi_param REMOTE_ADDR        $remote_addr;
fastcgi_param REMOTE_PORT        $remote_port;
fastcgi_param SERVER_ADDR        $server_addr;
fastcgi_param SERVER_PORT        $server_port;
fastcgi_param SERVER_NAME        $server_name;

fastcgi_param SCRIPT_FILENAME    $document_root$fastcgi_script_name;
fastcgi_param REDIRECT_STATUS    200;
```
