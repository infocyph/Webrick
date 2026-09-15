Runwire Runtime Performance
===========================

Webrick 5 includes a focused PHPBench fixture for the optional Runwire 1.x runtime bridge in ``benchmarks/RunwireRuntimeBench.php``. The purpose is to keep the adapter, request-promotion, scope and response-writing costs visible without adding a second benchmark harness or a production dependency.

Measurement setup
-----------------

The release-validation measurements below came from the existing PHPForge benchmark job on Ubuntu 24.04 with Xdebug disabled. PHPForge invoked PHPBench with:

.. code:: text

   vendor/bin/phpbench run --report=aggregate --output=json --progress=none \
       --bootstrap=vendor/autoload.php \
       --config=vendor/infocyph/phpforge/resources/phpbench.json \
       --revs=10 --iterations=3 --warmup=1 benchmarks

The table reports PHPBench ``mode`` values in microseconds. These are microbenchmarks of individual bridge operations, not end-to-end network latency or sustainable request throughput.

.. list-table:: Runwire bridge measurements
   :header-rows: 1
   :widths: 42 20 20 18

   * - Operation
     - PHP 8.4.25
     - PHP 8.5.10
     - What it measures
   * - Runwire context normalization
     - **9.18 µs**
     - **14.38 µs**
     - Native Runwire request to lightweight ``RuntimeRequestContext`` without forcing a full Webrick ``Request``.
   * - Fixed response write
     - **10.76 µs**
     - **17.06 µs**
     - Adapter context plus a small plaintext response through the Runwire writer contract.
   * - Full Request promotion
     - **26.30 µs**
     - **44.25 µs**
     - Lazy promotion from the runtime context into the complete Webrick ``Request`` representation.
   * - InterMix request-scope round trip
     - **0.80 µs**
     - **1.00 µs**
     - Enter and leave an otherwise empty Webrick request scope through ``InterMixRuntime``.
   * - Three-chunk streaming response write
     - **12.52 µs**
     - **19.48 µs**
     - Adapter context plus a three-chunk streamed response with an accepting writer.

The same PHP 8.4 benchmark run measured the existing development-kernel closure dispatch at about **7.89 µs** and static-controller dispatch at about **9.10 µs**; PHP 8.5 measured those at about **12.29 µs** and **14.81 µs** respectively. Cross-PHP differences therefore affect the wider benchmark suite as well and should not be interpreted as a Runwire-only regression.

Interpretation
--------------

The measurements support the current architecture rather than a new optimization layer:

- lightweight Runwire normalization is materially cheaper than eagerly promoting every request to a complete Webrick ``Request``;
- full ``Request`` promotion is intentionally paid only when the matched execution plan requires request-dependent behavior;
- an empty InterMix request-scope enter/leave is a sub-microsecond-to-one-microsecond operation in these runs;
- fixed and small streamed responses remain bounded adapter operations and do not introduce a second output queue;
- the benchmark exposed no isolated regression that justified adding a cache, scheduler, alternate scope store or special response pipeline.

Accordingly, Point 19 required **no production-code tuning**. Preserving the existing lazy-request and zero-scope paths is preferable to adding complexity without a measured benefit.

Benchmark limits
----------------

The current Webrick workflow uploads raw PHP 8.4 and PHP 8.5 benchmark artifacts, but this repository does not configure a checked-in ``benchmark_result_file`` / ``benchmark_baseline_file`` pair for PHPForge's automatic regression comparison. The benchmark job therefore records and validates execution, while release decisions still require direct inspection of the measured bridge costs and representative application benchmarks.

Do not turn the numbers above into universal latency budgets. For production decisions, measure the selected Runwire host/driver with representative routes, middleware, request/response sizes, concurrency, transport protocol, OPcache settings and real downstream work. End-to-end throughput, p50/p95/p99 latency, failures, CPU and memory remain the authoritative deployment measures.

Release decision
----------------

For Webrick 5's Runwire adapter release:

- no new runtime dependency was introduced for performance work;
- no production runtime code was changed solely to chase microbenchmark numbers;
- lazy request promotion remains mandatory for the optimized compiled path;
- zero-scope compiled routes remain free of InterMix request-scope work;
- Runwire remains the transport/lifecycle owner and Webrick does not add a competing scheduler or buffering layer;
- future tuning should require a reproducible regression in this fixture or representative end-to-end application measurements.
