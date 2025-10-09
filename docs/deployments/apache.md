
# Apache (mod_php or PHP-FPM)

Minimal configuration using `FallbackResource` (Apache 2.4+).

```apache
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot "/var/www/app/public"

    # Avoid double compression when app handles it
    SetEnvIfNoCase ^Accept-Encoding$ ".*" no-gzip=1

    <Directory "/var/www/app/public">
        AllowOverride All
        Require all granted
        FallbackResource /index.php
    </Directory>

    # If using PHP-FPM via ProxyPassMatch
    # ProxyPassMatch ^/(.*\.php(/.*)?)$ fcgi://127.0.0.1:9000/var/www/app/public/$1
</VirtualHost>
```

## Notes
- Keep `FallbackResource /index.php` to ensure all routes go through the front controller.
- If using `mod_php`, ensure `zlib.output_compression Off` to avoid double encoding.
