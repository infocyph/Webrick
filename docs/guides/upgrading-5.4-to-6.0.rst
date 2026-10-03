Upgrading from Webrick 5.4 to 6.0
=================================

Webrick 6.0 is a major release. This guide records the deployment-visible
changes that must be handled when upgrading an application from Webrick 5.4.
It will be extended as the remaining Webrick 6.0 implementation batches land.

Response-cache namespace
------------------------

Webrick 6.0 changes the shared HTTP response-cache namespace from
``webrick.hr.v3.`` to ``webrick.hr.v4.``.

The cache key now hashes the exact raw query string instead of a lossy
normalized form. Query strings that differ in ordering, encoding, delimiters,
duplicate keys, scalar/array overwrite order or ``+`` versus ``%2B`` no longer
share a cache entry merely because the old normalizer collapsed them.

The old namespace is intentionally not reused. Existing 5.4 response-cache
entries are disposable and may be allowed to expire or be removed during the
upgrade. Do not copy or rewrite old response-cache values into the 6.0
namespace.

Protected cookie names
----------------------

Encrypted-cookie names using the configured protected prefix must have a
non-empty, dot-free base name in Webrick 6.0.

For example, ``enc_session`` remains supported while
``enc_session.extra`` is rejected. This keeps the ``.pN`` suffix reserved for
Webrick's segmented encrypted-cookie format and prevents a prefix-matching
cookie from bypassing authenticated decryption as plaintext.

Applications that currently emit dotted protected-cookie names must rename
those cookies before upgrading and expire the old client-side names.

Server-side encrypted cookie storage
------------------------------------

Stored encrypted-cookie references remain shaped as ``S:<32 lowercase hex>``.
Webrick 6.0 only accepts references whose backing record uses the current
``C1:`` authenticated ciphertext wrapper.

The implicit 5.4 compatibility path that accepted plaintext or unframed legacy
cache records is removed. Those records must not be copied into a 6.0
deployment. Rotate or invalidate legacy cookie-store entries and require users
to obtain fresh encrypted cookies after the cutover.

Current ``C1:`` values retain AES-GCM authentication and cookie-name binding;
moving one stored reference from one protected cookie name to another does not
make it valid.

Redirect hardening
------------------

Redirect ``Location`` values are validated more strictly. Ambiguous backslash
forms, malformed authorities, userinfo, invalid ports and absolute/network-path
targets without a valid host are rejected before host allow-list evaluation.

Ordinary relative references, fragments, queries and explicitly allowed
same-host absolute or network-path redirects remain supported.

Deployment sequence for these Batch 1 changes
---------------------------------------------

1. Deploy application code that no longer depends on dotted protected-cookie
   names or legacy plaintext/unframed cookie-store values.
2. Drain the previous Webrick 5.4 generation before switching persistent
   workers.
3. Expire or isolate the old response-cache namespace.
4. Remove or expire legacy encrypted-cookie store records rather than
   translating them.
5. Deploy Webrick 6.0 and allow fresh response-cache and cookie state to be
   created under the new contracts.
