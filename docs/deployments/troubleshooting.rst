Troubleshooting
===============

403 on signed URLs
------------------

- Check that the full query string is preserved by the proxy (``$query_string`` in Nginx).
- Ensure ``WEBRICK_SIGN_KEY`` matches between generator and verifier.
- If using temporary URLs, confirm clock skew and TTL (``signedDefaultTtl``).
- Verification failures are raised internally as HTTP exceptions and rendered by the kernel error boundary, so custom top-level error rendering still applies.

406 Not Acceptable
------------------

- Negotiation middleware rejects unacceptable ``Accept`` headers. Ensure your client requests supported types.
- The middleware now throws a framework HTTP exception internally; the kernel error boundary renders the final ``406``.
- Use ``Response::auto()`` for convenience.

Weird caches
------------

- Verify ``Vary`` headers include ``Accept-Encoding``/``Accept-Language`` as emitted by middleware.
- Avoid stripping or overwriting ``Vary`` at the proxy/CDN.
