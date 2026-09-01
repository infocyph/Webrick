Gateway Hardening Middleware
============================

Protects the app at the edge of the request pipeline.

Responsibilities
----------------

- Host allow-list & HTTPS enforcement (308 redirects as configured).
- Trusted proxy handling; strips hop-by-hop headers.
- Guard against open redirects and invalid ``X-Forwarded-*`` chains.

Placement
---------

Put it near the top of ``preGlobal`` (after validators if you want validators to short-circuit first).

.. code:: php

   preGlobal: [
     \Infocyph\Webrick\Middleware\CacheValidatorsMiddleware::class,
     \Infocyph\Webrick\Middleware\GatewayHardeningMiddleware::class,
     // ...
   ]

--------------

Configuration
-------------

.. code:: php

   use Infocyph\Webrick\Middleware\GatewayHardeningMiddleware;

   $preGlobal[] = new GatewayHardeningMiddleware(
       trustedProxyCidrs: ['10.0.0.0/8', '172.16.0.0/12'],  // Trusted proxy IPs
       denyIpCidrs: ['192.168.1.100/32'],                    // Blocked IPs
       trustedHosts: ['example.com', '*.example.com'],      // Host whitelist
       forwardedHeaderMask: null,                            // All supported forwarded headers
       enforceHttps: true,                                   // Force HTTPS
       httpsPort: 443,                                       // HTTPS port
       stripHopByHop: true,                                  // Strip Connection, Keep-Alive, etc.
       redirectAllowedHosts: []                              // Open redirect guard
   );

Constructor Parameters
~~~~~~~~~~~~~~~~~~~~~~

+--------------------------+-------------------+----------+---------------------------------------------------+
| Parameter                | Type              | Default  | Description                                       |
+==========================+===================+==========+===================================================+
| ``trustedProxyCidrs``    | ``array<string>`` | ``[]``   | CIDR ranges of trusted proxies                    |
+--------------------------+-------------------+----------+---------------------------------------------------+
| ``denyIpCidrs``          | ``array<string>`` | ``[]``   | CIDR ranges to block                              |
+--------------------------+-------------------+----------+---------------------------------------------------+
| ``trustedHosts``         | ``array<string>`` | ``[]``   | Allowed Host header values                        |
+--------------------------+-------------------+----------+---------------------------------------------------+
| ``forwardedHeaderMask``  | ``?int``          | ``null`` | Trusted-header bitmask (``null`` = all supported) |
+--------------------------+-------------------+----------+---------------------------------------------------+
| ``enforceHttps``         | ``bool``          | ``true`` | Redirect HTTP → HTTPS                             |
+--------------------------+-------------------+----------+---------------------------------------------------+
| ``httpsPort``            | ``int``           | ``443``  | HTTPS port for redirects                          |
+--------------------------+-------------------+----------+---------------------------------------------------+
| ``stripHopByHop``        | ``bool``          | ``true`` | Remove hop-by-hop headers                         |
+--------------------------+-------------------+----------+---------------------------------------------------+
| ``redirectAllowedHosts`` | ``array<string>`` | ``[]``   | Allowed redirect destinations                     |
+--------------------------+-------------------+----------+---------------------------------------------------+

--------------

Features
--------

1. Host Validation
~~~~~~~~~~~~~~~~~~

Blocks requests with untrusted ``Host`` headers:

.. code:: php

   new GatewayHardeningMiddleware(
       trustedHosts: ['example.com', 'api.example.com', '*.partner.com']
   );

**Supported patterns**:

- Exact: ``example.com``
- Wildcard subdomain: ``*.example.com``
- Catch-all (dev only): ``['*']``

**Why**: Prevents Host header injection attacks.

2. IP Filtering
~~~~~~~~~~~~~~~

**Trusted Proxies**:

.. code:: php

   trustedProxyCidrs: ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']
   // Honor X-Forwarded-For from these IPs only

**IP Deny List**:

.. code:: php

   denyIpCidrs: ['203.0.113.0/24', '198.51.100.50/32']
   // Block these IPs entirely

3. HTTPS Enforcement
~~~~~~~~~~~~~~~~~~~~

Redirects HTTP → HTTPS with 308 (permanent):

.. code:: php

   new GatewayHardeningMiddleware(
       enforceHttps: true,
       httpsPort: 443  // Omits :443 from Location header
   );

**Example**:

::

   Request:  http://example.com/page
   Response: 308 Permanent Redirect
             Location: https://example.com/page

4. Hop-by-Hop Header Stripping
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Removes headers that shouldn't pass through proxies:

**Removed**:

- ``Connection``
- ``Keep-Alive``
- ``Proxy-Authenticate``
- ``Proxy-Authorization``
- ``TE``
- ``Trailer``
- ``Transfer-Encoding``
- ``Upgrade``

**Safe for HTTP/2** (never emits ``Connection``).

5. Open Redirect Protection
~~~~~~~~~~~~~~~~~~~~~~~~~~~

Validates ``Location`` headers to prevent open redirects:

.. code:: php

   new GatewayHardeningMiddleware(
       redirectAllowedHosts: []  // Empty = same-origin only
   );

   // Or explicit whitelist
   new GatewayHardeningMiddleware(
       redirectAllowedHosts: ['cdn.example.com', 'assets.example.com']
   );

**Blocks**:

- Schemes other than ``http``/``https`` (prevents ``javascript:``, ``data:``, etc.)
- External hosts not in whitelist

--------------

Request Attributes
------------------

Middleware attaches useful attributes:

.. code:: php

   $r->getAttribute('client_ip');         // End-user IP (honors proxies)
   $r->getAttribute('peer_ip');           // Direct socket peer
   $r->getAttribute('is_trusted_proxy');  // bool
   $r->getAttribute('effective_scheme');  // Normalized scheme
   $r->getAttribute('effective_host');    // Hostname without port
   $r->getAttribute('effective_port');    // Non-default port or null

Forwarded values are used only when the direct socket peer matches ``trustedProxyCidrs``. Each middleware instance owns its proxy policy, which keeps separate applications isolated in persistent workers.

--------------

Examples
--------

Production (Behind AWS ALB)
~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   new GatewayHardeningMiddleware(
       trustedProxyCidrs: ['10.0.0.0/8'],  // VPC CIDR
       trustedHosts: ['example.com', 'www.example.com'],
       enforceHttps: true,
       stripHopByHop: true
   );

Development (Local)
~~~~~~~~~~~~~~~~~~~

.. code:: php

   new GatewayHardeningMiddleware(
       trustedHosts: ['localhost', '*.localhost', '127.0.0.1'],
       enforceHttps: false  // Allow HTTP in dev
   );

CDN + Origin
~~~~~~~~~~~~

.. code:: php

   new GatewayHardeningMiddleware(
       trustedProxyCidrs: [
           '103.21.244.0/22',  // Cloudflare IPs
           '103.22.200.0/22',
           // ... (all Cloudflare ranges)
       ],
       trustedHosts: ['example.com'],
       enforceHttps: true
   );

--------------

Troubleshooting
---------------

Issue: Wrong client IP detected
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Cause**: Proxy not trusted, or ``X-Forwarded-For`` not honored.

**Fix**: Add proxy to trusted list:

.. code:: php

   trustedProxyCidrs: ['10.0.0.0/8']

Issue: HTTPS redirect loop
~~~~~~~~~~~~~~~~~~~~~~~~~~

**Cause**: Proxy terminates TLS but doesn't set ``X-Forwarded-Proto``.

**Fix**: Configure proxy:

.. code:: nginx

   # Nginx
   proxy_set_header X-Forwarded-Proto $scheme;

Or disable enforcement:

.. code:: php

   enforceHttps: false

Issue: Host header rejected
~~~~~~~~~~~~~~~~~~~~~~~~~~~

**Cause**: Valid host not in ``trustedHosts``.

**Fix**: Add host:

.. code:: php

   trustedHosts: ['example.com', 'api.example.com']

--------------

Best Practices
--------------

✅ **Do**
~~~~~~~~~

1. **Whitelist exact hosts**

   .. code:: php

      trustedHosts: ['example.com', 'api.example.com']

2. **Trust only known proxies**

   .. code:: php

      trustedProxyCidrs: ['10.0.0.0/8']  // Your VPC

3. **Enforce HTTPS in production**

   .. code:: php

      enforceHttps: $_ENV['APP_ENV'] === 'production'

4. **Validate redirects**

   .. code:: php

      redirectAllowedHosts: []  // Same-origin only

❌ **Don't**
~~~~~~~~~~~~

1. **Don't trust all proxies**

   .. code:: php

      trustedProxyCidrs: ['0.0.0.0/0']  // ❌ DANGEROUS

2. **Don't use catch-all hosts in production**

   .. code:: php

      trustedHosts: ['*']  // ❌ Defeats the purpose

3. **Don't skip IP validation**

   .. code:: php

      // ❌ Trusting X-Forwarded-For without validation
      $ip = $request->getHeaderLine('X-Forwarded-For');
