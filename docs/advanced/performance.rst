Performance Tuning
==================

Production-grade optimization checklist for Webrick applications.

--------------

Benchmarking Baseline
---------------------

Use ``wrk`` or ``ab`` to establish baseline:

.. code:: bash

   # Install wrk
   apt-get install wrk  # or brew install wrk

   # Benchmark
   wrk -t4 -c100 -d30s http://127.0.0.1:8000/ping

   # Record successful RPS/RPM, p50/p95/p99, failures, CPU and memory.
   # Compare medians across repeated production-equivalent runs.

--------------

Optimization Checklist
----------------------

✅ **1. Enable OPcache** (Critical)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

**php.ini** (production):

.. code:: ini

   opcache.enable=1
   opcache.enable_cli=0
   opcache.validate_timestamps=0    ; Immutable releases (deploy by symlink flip)
   opcache.revalidate_freq=0
   opcache.jit=disable               ; Keep predictable unless proven faster

   ; Size to your codebase
   opcache.memory_consumption=256
   opcache.interned_strings_buffer=32
   opcache.max_accelerated_files=20000
   opcache.max_wasted_percentage=5
   opcache.save_comments=1

Measure cold and warm behavior separately; OPcache gains depend on the complete application, deployment mode and runtime configuration.

--------------

✅ **2. Prebuild Route Cache** (High Impact)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: bash

   # In CI/build step
   php ./webrick route:cache --matcher=fused --cache=.route-cache/fused.php --routes=routes.php

Ship the generated route-cache artifact with your release.

.. code:: php

   $kernel = RouterKernel::bootWithRegistrar(
       log: $logger,
       matcher: FusedMatcher::make(),
       register: $register,
       invoker: $invoker,
   );

Measure cache generation, cached kernel boot and matched-route dispatch as three separate costs. Cache generation may become slower when that removes validation, reflection and serialization from normal requests.

Fused and Generated modes publish a single PHP matcher artifact. Sharded mode publishes an immutable generation through one atomic manifest switch, so a partial shard generation is never selected by a new kernel.

Upload specifications remain raw until uploaded files are requested. Webrick still opens the PSR-compatible request body stream for every method, including GET and HEAD, because those methods may legally carry a body. URL-encoded body parsing remains gated to applicable non-POST methods and content types.

--------------

✅ **3. Select a Matcher from Representative Measurements**
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   $matcher = FusedMatcher::make();

Use Fused as the default/general production matcher and canonical comparison baseline. The Webrick 5 matcher revision moved Fused and Sharded onto the same compact compiled route-discrimination engine. Fused keeps that compiled IR in one artifact and provides the strongest general warm-dispatch behavior.

Use these route-count bands only as **benchmarking heuristics**, not hard switches:

+-------------------------+--------------------------------------+----------------------------------------------------------------------------------------------------------------------------------------+
| Approximate route count | Recommended starting point           | When another matcher becomes interesting                                                                                               |
+=========================+======================================+========================================================================================================================================+
| **< 100**               | ``FusedMatcher``                     | Benchmark Generated for mostly-static, isolated or strongly distinct route sets.                                                       |
+-------------------------+--------------------------------------+----------------------------------------------------------------------------------------------------------------------------------------+
| **100–1,000**           | ``FusedMatcher``                     | Generated can still win static/distinct cases, but dense shared-prefix dynamic families already favor Fused strongly.                  |
+-------------------------+--------------------------------------+----------------------------------------------------------------------------------------------------------------------------------------+
| **1,000–5,000**         | ``FusedMatcher``                     | Generated should be exceptional and benchmark-proven; Sharded usually matters only if startup/working-set pressure is already visible. |
+-------------------------+--------------------------------------+----------------------------------------------------------------------------------------------------------------------------------------+
| **5,000–10,000**        | ``FusedMatcher`` for warm throughput | Start benchmarking Sharded if route-cache boot, per-worker loaded state or deployment startup is becoming material.                    |
+-------------------------+--------------------------------------+----------------------------------------------------------------------------------------------------------------------------------------+
| **10,000+**             | Benchmark Fused and Sharded          | Fused favors warm request speed; Sharded favors lazy startup/working-set behavior. Generated is not a general large-route strategy.    |
+-------------------------+--------------------------------------+----------------------------------------------------------------------------------------------------------------------------------------+

Generated is therefore a **small-route specialization**, not the recommended "maximum matcher throughput" mode. It was fastest on Webrick's small native corpus and on some 100/1,000-route static or strongly distinct cases, but dense shared-prefix dynamic families degraded much earlier. At 5,000 and 10,000 routes, the generated function itself became large enough that even static dispatch was measured in tens of microseconds while Fused stayed around a few hundred nanoseconds. As a practical rule, Generated is most worth trying below roughly **100 routes**, can remain useful into the hundreds and occasionally around **1,000 routes**, and should always be re-benchmarked after substantial route-set growth.

Sharded becomes increasingly relevant when very large route sets make cold boot or startup working set the primary constraint. Begin measuring it around roughly **5,000 routes** when those costs become visible. Around **10,000+ routes**, a Fused-vs-Sharded deployment benchmark is recommended rather than assuming one artifact shape is universally better. The Webrick 5 5,000-route matcher profile showed dramatically cheaper Sharded cold boot, but also a slower first shard hit and slower warm dispatch. Sharding is therefore a startup/working-set strategy, not a blanket throughput strategy.

Fused remains valid across the whole range. The 10,000-route certification kept its structured dynamic, 404 and 405 dispatch around a few microseconds while the reference FastRoute and Generated paths grew substantially with the corpus. A large route count alone is therefore not a reason to leave Fused.

Route count alone never determines the winner: benchmark with the application's static/dynamic mix, shared versus distinct prefixes, domains, OPcache settings, worker lifetime, filesystem and traffic distribution. For a middleware-free route, Webrick uses a direct dispatch lane and does not allocate a middleware pipeline. Adding any pre-global, route, or post-global middleware intentionally selects the full ordered pipeline.

--------------

✅ **4. Minimize Pre-Global Middleware**
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Remove unused middleware in production:

.. code:: php

   $preGlobal = [
       // ✅ Keep
       GatewayHardeningMiddleware::class,
       TelemetryMiddleware::class,
       CacheValidatorsMiddleware::class,
       NegotiationMiddleware::class,

       // ❌ Remove in prod
       // ResponseLinterMiddleware::class,  // Dev only
       // MaintenanceModeMiddleware::class,  // Only during maintenance
   ];

Measure every global layer as part of end-to-end RPM. Webrick retains its standard pre/post tag names by default. Pass empty ``preGlobalTags`` and ``postGlobalTags`` lists when an application does not use tagged middleware and wants to remove even the container tag lookup from boot.

--------------

✅ **5. Compression Settings**
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   new CompressionMiddleware(
       minBytes: 1400,              // MTU-friendly (1 packet)
       prefOrder: ['zstd', 'br', 'gzip'],
       etagMode: CompressionMiddleware::ETAG_STRONG_DERIVE,  // Avoid recomputing hash
       maxBufferBytes: 8_388_608    // 8MB safety ceiling
   );

Codec throughput and compression ratio depend on payload, level, extension, CPU and response size. Measure the supported codecs on representative traffic, and compress at Webrick or the edge—not both.

--------------

✅ **6. Response Cache (Micro-Cache)**
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: php

   new ResponseCacheMiddleware(
       ttlSeconds: 5,       // 5-second micro-cache
       includeQuery: true,
       maxBodyBytes: 1_048_576  // 1MB
   );

Measure hit, miss and fill paths separately. A hit bypasses the handler, but the end-to-end improvement depends on handler cost, backend latency and hit rate.

**Best for**:

- Product listings
- Public APIs
- Read-heavy endpoints

--------------

✅ **7. PHP-FPM Tuning**
~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: ini

   ; /etc/php/8.4/fpm/pool.d/www.conf

   [www]
   pm = static                    ; Or 'dynamic' for variable load
   pm.max_children = 24           ; floor(RAM_for_PHP / avg_worker_RSS)
   pm.max_requests = 1000         ; Recycle to avoid leaks

   request_terminate_timeout = 120s
   request_slowlog_timeout = 3s
   slowlog = /var/log/php-fpm-slow.log

**Sizing ``max_children``**:

.. code:: bash

   # Measure average worker RSS
   ps -o rss= -C php-fpm8.4 | awk '{sum+=$1; n++} END {print "avg_mb=" sum/n/1024}'

   # Example: 90MB per worker, 2.2GB for PHP
   # max_children = floor(2200 / 90) = 24

--------------

✅ **8. Nginx Tuning**
~~~~~~~~~~~~~~~~~~~~~~

.. code:: nginx

   worker_processes auto;
   worker_connections 2048;

   # Disable buffering for API
   location /api/ {
       fastcgi_buffering off;
       fastcgi_pass unix:/run/php/php8.4-fpm.sock;
   }

   # Cache static assets
   location ~* \.(css|js|png|jpg|jpeg|gif|ico|woff2)$ {
       expires 30d;
       add_header Cache-Control "public, immutable";
   }

--------------

✅ **9. Choose Database Connections for the Runtime**
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Database connection lifetime is owned by the embedding application. Persistent PDO connections can help or hurt depending on the SAPI, transaction hygiene, server limits and workload. Benchmark the selected strategy and bound total connections; Webrick does not require persistent PDO.

--------------

✅ **10. Avoid Attribute Scanning in Runtime**
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Prebuild attribute routes into route cache:

.. code:: php

   // In build script
   RouteCache::build([
       'register' => static function (Registrar $r): void {
           require __DIR__ . '/../routes.php';

           // Scan attributes during build, not runtime
           AttributeRouteLoader::registerFromDirs($r, [
               'App\\Http\\Routes\\' => __DIR__ . '/../src/Http/Routes'
           ]);
       }
   ]);

Record discovery time separately from cached boot. The cost varies with the class set, filesystem, autoloader, PHP build and cache warmth.

--------------

Profiling
---------

Xdebug Profiler (Dev)
~~~~~~~~~~~~~~~~~~~~~

.. code:: bash

   php -d xdebug.mode=profile \
       -d xdebug.output_dir=/tmp \
       public/index.php

Analyze with **QCacheGrind** or **KCacheGrind**.

Blackfire.io (Prod-Safe)
~~~~~~~~~~~~~~~~~~~~~~~~

.. code:: bash

   blackfire curl http://example.com/api/users

Simple Timing
~~~~~~~~~~~~~

.. code:: php

   final class TimingMiddleware
   {
       public function __invoke(Request $req, Closure $next): Response
       {
           $start = hrtime(true);
           $resp = $next($req);
           $duration = (hrtime(true) - $start) / 1e6;

           error_log(sprintf("[%s] %s: %.2fms",
               $req->getAttribute('request_id'),
               $req->getPath(),
               $duration
           ));

           return $resp;
       }
   }

--------------

Common Bottlenecks
------------------

+-----------------------+--------------------+------------------------------------------+
| Issue                 | Symptom            | Fix                                      |
+=======================+====================+==========================================+
| Cold OPcache          | First hit slow     | Warm cache post-deploy                   |
+-----------------------+--------------------+------------------------------------------+
| Attribute scanning    | Cold-boot overhead | Measure it; prebuild route cache         |
+-----------------------+--------------------+------------------------------------------+
| Large JSON responses  | High memory        | Use pagination; enable compression       |
+-----------------------+--------------------+------------------------------------------+
| N+1 queries           | DB load spikes     | Eager load; use query logging            |
+-----------------------+--------------------+------------------------------------------+
| No response cache     | Redundant work     | Add ResponseCacheMiddleware for hot GETs |
+-----------------------+--------------------+------------------------------------------+
| Double compression    | CPU waste          | Pick edge OR app, not both               |
+-----------------------+--------------------+------------------------------------------+
| Unindexed DB columns  | Slow queries       | Add indexes; analyze EXPLAIN             |
+-----------------------+--------------------+------------------------------------------+
| Too many pre-globals  | High latency       | Remove unused middleware                 |
+-----------------------+--------------------+------------------------------------------+
| Small FPM pool        | 502 errors         | Size ``pm.max_children`` by memory       |
+-----------------------+--------------------+------------------------------------------+
| Connection saturation | Timeouts/queueing  | Bound and measure application DB usage   |
+-----------------------+--------------------+------------------------------------------+

--------------

Production Checklist
--------------------

- ☐ OPcache enabled (``validate_timestamps=0``)
- ☐ Route cache prebuilt in CI
- ☐ Fused used as the default matcher at any size; Generated benchmarked mainly for small/simple corpora; Sharded evaluated around several thousand routes when startup/working-set needs become material
- ☐ Compression enabled (app OR edge, not both)
- ☐ Response cache for hot GETs
- ☐ PHP-FPM sized by memory
- ☐ Database connection strategy measured for the deployment runtime
- ☐ Static assets cached at edge
- ☐ Unnecessary middleware removed
- ☐ Profiling set up (Blackfire/Xdebug)

--------------

Publishing Benchmark Results
----------------------------

Publish fixed numbers only with the Webrick commit, PHP build, OPcache/JIT settings, CPU, RAM, operating system, web server, worker configuration, concurrency, route set, matcher, middleware stack, command, duration, warm/cold state, failures and p50/p95/p99 latency. Compare median sustained successful RPM across repeated production-equivalent runs.
