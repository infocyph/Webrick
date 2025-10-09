
# Troubleshooting

## 403 on signed URLs
- Check that the full query string is preserved by the proxy (`$query_string` in Nginx).
- Ensure `WEBRICK_SIGN_KEY` matches between generator and verifier.
- If using temporary URLs, confirm clock skew and TTL (`signedDefaultTtl`).

## 406 Not Acceptable
- Negotiation middleware rejects unacceptable `Accept` headers. Ensure your client requests supported types.
- Use `Response::auto()` for convenience.

## Weird caches
- Verify `Vary` headers include `Accept-Encoding`/`Accept-Language` as emitted by middleware.
- Avoid stripping or overwriting `Vary` at the proxy/CDN.
