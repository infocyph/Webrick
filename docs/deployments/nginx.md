
# Nginx (Reverse Proxy) + PHP-FPM

A minimal, production‑ready Nginx config when Webrick runs under PHP‑FPM.

```nginx
server {
    listen 80;
    server_name example.com;

    # Serve public assets directly
    root /var/www/app/public;

    # Real IP / Proxy params (adjust for your infra)
    set_real_ip_from  10.0.0.0/8;
    real_ip_header    X-Forwarded-For;
    real_ip_recursive on;

    # Avoid double compression when app handles it
    gzip off;

    # Static assets (immutable)
    location ~* \.(?:css|js|png|jpg|jpeg|gif|ico|svg|woff2?)$ {
        access_log off;
        add_header Cache-Control "public, max-age=31536000, immutable";
        try_files $uri =404;
    }

    # Front controller
    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_PROXY "";                # mitigate CVE-2016-5385
        fastcgi_pass unix:/run/php/php-fpm.sock;    # adjust
        fastcgi_read_timeout 300;
        # Disable buffering for streaming endpoints if desired:
        # fastcgi_buffering off;
    }
}
```

## Notes
- If you enable Nginx `gzip on;`, disable Webrick's Compression middleware.
- Preserve the **query string** for signed/temporary URLs (`$query_string`).
- Use 308 redirects at the app or a map block for HTTPS enforcement; keep it consistent with gateway hardening.
