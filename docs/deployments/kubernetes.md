
# Kubernetes (Ingress + PHP-FPM Deployment)

High‑level manifests (trim to your cluster standards).

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: webrick-app
spec:
  replicas: 3
  selector: { matchLabels: { app: webrick } }
  template:
    metadata: { labels: { app: webrick } }
    spec:
      containers:
        - name: php-fpm
          image: ghcr.io/yourorg/webrick-app:latest
          env:
            - name: WEBRICK_SIGN_KEY
              valueFrom: { secretKeyRef: { name: webrick, key: sign_key } }
            - name: WEBRICK_COOKIE_KEY
              valueFrom: { secretKeyRef: { name: webrick, key: cookie_key } }
          ports: [{ containerPort: 9000 }]
---
apiVersion: v1
kind: Service
metadata: { name: webrick-fpm }
spec:
  selector: { app: webrick }
  ports: [{ port: 9000, targetPort: 9000 }]
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata: { name: webrick }
spec:
  rules:
    - host: example.com
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: web-nginx
                port: { number: 80 }
```

**Notes**
- Use Nginx ingress with `proxy_buffering off` on streaming paths if needed.
- Ensure `X-Forwarded-*` headers are set so gateway hardening works correctly.
