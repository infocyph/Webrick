
# Containers (Nginx + PHP-FPM + Supervisor)

A simple Docker Compose snippet for production‑like setups.

```yaml
version: "3.9"
services:
  app:
    image: php:8.4-fpm-alpine
    working_dir: /var/www/app
    volumes:
      - ./:/var/www/app:ro
    environment:
      WEBRICK_SIGN_KEY: ${WEBRICK_SIGN_KEY}
      WEBRICK_COOKIE_KEY: ${WEBRICK_COOKIE_KEY}
    healthcheck:
      test: ["CMD", "php", "-v"]
      interval: 30s
      timeout: 5s
      retries: 3

  web:
    image: nginx:alpine
    volumes:
      - ./:/var/www/app:ro
      - ./ops/nginx.conf:/etc/nginx/conf.d/default.conf:ro
    ports:
      - "80:80"
    depends_on:
      - app
```

### Build tips
- Use multi‑stage images to copy vendor + built assets into a slim final image.
- Enable OPcache/JIT for FPM; tune `pm`, `pm.max_children` per CPU/memory.
