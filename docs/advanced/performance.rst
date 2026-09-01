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

Use these route-count bands only as **benchmarking heuristics**, not hard switches. The current Webrick 5 cache-envelope measurements deliberately keep matcher selection explicit because route topology can move the crossover materially.

.. list-table:: Matcher selection starting points
   :header-rows: 1
   :widths: 18 24 58

   * - Approximate route count
     - Recommended starting point
     - What to benchmark
   * - **< 1,000**
     - ``FusedMatcher`` as the safe default
     - Generated is a strong warm-latency candidate for simple/static/distinct route sets and should be benchmarked early when matcher latency matters.
   * - **1,000–1,500**
     - Benchmark Fused and Generated
     - In the current synthetic cache envelope Generated materially beat Fused throughout this band; real topology still decides the winner.
   * - **1,500–2,250**
     - Explicit crossover zone
     - Generated reached near parity with Fused around 1,750–2,000 routes. Measure both rather than selecting from route count alone.
   * - **2,250–5,000**
     - ``FusedMatcher``
     - Fused was generally faster and has lower artifact/boot cost. Generated can show isolated topology-specific wins, so retain it only when representative measurements prove one.
   * - **5,000–10,000**
     - Fused for warm throughput
     - Benchmark Sharded when cache boot or loaded working set matters. Generated is not a general strategy in this range.
   * - **10,000+**
     - Benchmark Fused and Sharded
     - Fused favors fully resident low-latency dispatch; Sharded favors lazy startup and bounded loaded state.

Generated is therefore a **small-to-medium/simple-topology specialization**, not merely a sub-100-route matcher. In the current PHP 8.4.25 synthetic cache envelope (OPcache disabled), Generated remained clearly ahead through roughly **1,500 routes** and reached near parity around **1,750–2,000 routes**. Fused became the generally safer warm-latency choice from roughly **2,250 routes onward**.

That crossover is deliberately not encoded as an automatic matcher switch. Generated produced non-monotonic isolated results around 3,500 and 4,500 routes, demonstrating that generated control-flow shape matters as much as raw route count. At 5,000 routes the same envelope hit a severe generated-code cliff: median warm dispatch was about **69.001 µs** for Generated versus **1.745 µs** for Fused, while the Generated cache artifact was about **26.04 MB** versus **9.76 MB** for Fused.

Sharded becomes relevant when cold boot or loaded working set matters enough to justify a first-shard load. The retained Webrick 5 candidate-group memoization cut cached Sharded warm latency by roughly **57–61%** in same-run measurements: about **2.08 µs** at 1,000 routes, **2.24 µs** at 5,000 and **2.41 µs** at 10,000, while first-hit latency remained effectively neutral. At 10,000 routes the isolated cached profile measured about **57 µs** initial boot, **986 µs** first shard hit and **2.49 µs** warm dispatch.

Fused remains valid across the whole measured range. Its fully resident compact IR keeps representative warm dispatch close to route-count independent, while its costs are paid in cache boot and resident memory. Use it as the general production baseline, Generated when the real route set proves a generated-code advantage, and Sharded when lazy residency is the deployment priority.

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
- ☐ Fused used as the general default; Generated benchmarked seriously for simple route sets into the low thousands; Sharded evaluated when startup/working-set needs become material
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
