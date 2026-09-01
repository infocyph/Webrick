from __future__ import annotations

from pathlib import Path


def replace_between(text: str, start: str, end: str, replacement: str) -> str:
    left = text.find(start)
    if left < 0:
        raise SystemExit(f'missing start marker: {start!r}')
    right = text.find(end, left)
    if right < 0:
        raise SystemExit(f'missing end marker: {end!r}')
    return text[:left] + replacement + text[right:]


def replace_once(text: str, old: str, new: str) -> str:
    if text.count(old) != 1:
        raise SystemExit(f'expected exactly one occurrence of: {old!r}')
    return text.replace(old, new, 1)


# Public performance guidance -------------------------------------------------
path = Path('docs/advanced/performance.rst')
text = path.read_text()
start = 'Use these route-count bands only as **benchmarking heuristics**, not hard switches:\n'
end = '--------------\n\n✅ **4. Minimize Pre-Global Middleware**'
replacement = '''Use these route-count bands only as **benchmarking heuristics**, not hard switches. The current Webrick 5 cache-envelope measurements deliberately keep matcher selection explicit because route topology can move the crossover materially.

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

'''
text = replace_between(text, start, end, replacement)
text = replace_once(
    text,
    '- ☐ Fused used as the default matcher at any size; Generated benchmarked mainly for small/simple corpora; Sharded evaluated around several thousand routes when startup/working-set needs become material',
    '- ☐ Fused used as the general default; Generated benchmarked seriously for simple route sets into the low thousands; Sharded evaluated when startup/working-set needs become material',
)
path.write_text(text)


# Matcher reference ----------------------------------------------------------
path = Path('docs/reference/matcher.rst')
text = path.read_text()
text = replace_once(
    text,
    'Webrick provides three selectable matching strategies. Fused and Sharded share the same compact production matcher IR and request-time executor. Sharded changes the physical storage and loaded working-set boundary. Generated remains a separate generated-code strategy for small route topologies that specifically benefit from it.',
    'Webrick provides three selectable matching strategies. Fused and Sharded share the same compact production matcher IR and request-time executor. Sharded changes the physical storage and loaded working-set boundary. Generated remains a separate generated-code strategy for route topologies whose generated control flow stays compact and benchmark-proven.',
)
comparison_start = 'Comparison\n----------\n'
fused_header = 'Fused matcher\n-------------\n'
comparison = '''Comparison
----------

.. list-table:: Matcher roles
   :header-rows: 1
   :widths: 16 20 25 39

   * - Matcher
     - Artifact
     - Webrick 5 role
     - Approximate measured guidance
   * - ``FusedMatcher``
     - One PHP file
     - **Default/general production matcher**
     - Valid at any measured size and generally the safer warm-latency choice from roughly **2,250 routes onward**; remains flat through 10,000+ in the structured scale corpus.
   * - ``GeneratedMatcher``
     - One PHP file with generated matcher code
     - **Small-to-medium/simple-topology specialization**
     - Strong measured candidate through roughly **1,500 routes** in the current synthetic envelope; near parity around **1,750–2,000**. Benchmark explicitly beyond that and do not treat it as a general 5,000+ mode.
   * - ``ShardedMatcher``
     - Directory of immutable PHP shards
     - **Cold-boot / bounded-working-set specialization**
     - Evaluate when several-thousand-route cache boot or loaded state becomes material; warm dispatch is now much closer to Fused after candidate-group memoization.

These counts are **benchmarking heuristics, not thresholds**. Route topology can matter more than route count, and Generated's measured results are intentionally non-monotonic at some larger sizes. Webrick therefore does not auto-select a matcher from route count.

Route-count quick guide
~~~~~~~~~~~~~~~~~~~~~~~

.. list-table:: Route-count quick guide
   :header-rows: 1
   :widths: 14 23 30 20 30

   * - Routes
     - ``FusedMatcher``
     - ``GeneratedMatcher``
     - ``ShardedMatcher``
     - Practical starting point
   * - **< 1,000**
     - Excellent safe default.
     - Strong candidate for simple/static/distinct route sets; often worth benchmarking first for minimum warm latency.
     - Usually unnecessary.
     - Start Fused when topology is unknown; benchmark Generated early when matcher latency is important.
   * - **1,000–1,500**
     - Strong and predictable.
     - Current synthetic envelope materially favored Generated throughout this band.
     - Usually unnecessary unless startup pressure is unusual.
     - Benchmark Fused and Generated on the real route set.
   * - **1,500–2,250**
     - Strong and predictable.
     - Crossover zone: near parity was measured around 1,750–2,000.
     - Consider only for a specific residency/startup need.
     - Benchmark Fused and Generated; do not infer the winner from count alone.
   * - **2,250–5,000**
     - Generally preferred for warm latency and artifact efficiency.
     - Benchmark-only exception; isolated topology wins can occur, but the general trend favors Fused.
     - Becomes interesting when cold boot or working-set cost is visible.
     - Fused for normal deployments; compare Sharded when residency matters.
   * - **5,000–10,000**
     - Preferred for fully resident warm throughput.
     - Not a general choice; the current 5,000-route envelope shows a severe generated-code cliff.
     - Strong candidate when cold boot or per-worker loaded state matters.
     - Benchmark Fused versus Sharded according to runtime priorities.
   * - **10,000+**
     - Still valid and fast for warm dispatch.
     - Avoid as a general large-route mode without extraordinary application-specific evidence.
     - Strong candidate for lazy loading and startup/working-set reduction.
     - Benchmark Fused versus Sharded; choose by deployment tradeoff.

The strategies intentionally optimize different deployment/topology problems. There is no claim that one strategy wins every corpus.

'''
text = replace_between(text, comparison_start, fused_header, comparison + fused_header)

generated_start = 'Generated matcher\n-----------------\n'
sharded_header = 'Sharded matcher\n---------------\n'
generated = '''Generated matcher
-----------------

.. code:: php

   use Infocyph\\Webrick\\Router\\Matching\\GeneratedMatcher;

   $matcher = GeneratedMatcher::make();

Generated emits specialized PHP matching code. The completed Webrick 5 crossover study shows that its useful envelope is wider than the earlier documentation suggested: it is a real **small-to-medium/simple-topology specialization**, not merely a tiny-route mode.

In the current PHP 8.4.25 synthetic cache envelope with OPcache disabled, Generated materially beat Fused through roughly **1,500 routes** and reached near parity around **1,750–2,000 routes**. Representative medians were:

- 1,000 routes: **0.772 µs Generated** versus **1.606 µs Fused**;
- 1,500 routes: **0.979 µs** versus **1.738 µs**;
- 1,750 routes: **1.507 µs** versus **1.567 µs**;
- 2,000 routes: **1.575 µs** versus **1.623 µs**;
- 2,250 routes: **1.776 µs** versus **1.626 µs**, where Fused became the generally safer choice.

These numbers are not a route-count switch. Generated showed isolated near-wins again around 3,500 and 4,500 routes, which demonstrates that branch/code shape and route topology can move the crossover. Webrick therefore keeps Generated explicit rather than auto-selecting it.

Generated also pays more for build, cache artifact, boot and resident code. At **5,000 routes** the measured generated representation crossed a severe execution/code-size cliff: median warm dispatch was about **69.001 µs** versus **1.745 µs** for Fused, and the cache artifact was about **26.04 MB** versus **9.76 MB**. It is therefore not a general large-route strategy.

Use Generated when representative application measurements prove that its generated branches win. Re-run that measurement after material route-set or topology changes, and include cache build/boot/artifact cost when choosing it for short-lived workers.

'''
text = replace_between(text, generated_start, sharded_header, generated + sharded_header)

sharded_start = sharded_header
shared_header = 'Routing semantics shared by the strategies\n------------------------------------------\n'
sharded = '''Sharded matcher
---------------

.. code:: php

   use Infocyph\\Webrick\\Router\\Matching\\ShardedMatcher;

   $matcher = ShardedMatcher::make();

The cache location is a directory. Routes are partitioned by host/path grouping, and each loaded shard uses the same compact central-route-table IR and executor as Fused. Sharding therefore changes storage/startup behavior rather than route semantics.

Sharded is useful when route-cache boot or loaded working set matters more than keeping every compiled group resident. Begin measuring it once several-thousand-route applications make those costs visible; around 5,000+ routes it becomes a natural Fused comparison when workers do not need the whole route table immediately.

The final Webrick 5 residency pass removed an avoidable cached hot-path cost by memoizing resolved candidate groups per host/prefix. Same-run warm latency improved from **5.277 → 2.082 µs** at 1,000 routes, **5.348 → 2.237 µs** at 5,000, and **5.633 → 2.405 µs** at 10,000, while first-shard latency remained effectively neutral. An isolated 10,000-route cached profile measured roughly **57 µs cold boot**, **986 µs first shard hit**, and **2.49 µs warm dispatch**.

Fused remains faster once its complete IR is resident, while Sharded can boot with almost no matcher state loaded and materialize only the route groups traffic touches. Persistent workers amortize first-shard loading; short-lived processes should compare the complete cold/first/warm envelope before choosing a mode.

A large route count alone does not make Sharded faster. It makes Sharded's startup and working-set tradeoff more likely to be useful.

'''
text = replace_between(text, sharded_start, shared_header, sharded + shared_header)

recommendation = '''Recommendation
--------------

- Start with **``FusedMatcher`` at any route count** when the application's topology has not yet been measured. It remains Webrick 5's canonical general-production baseline.
- For simple/static/distinct applications up to roughly **1,500 routes**, benchmark **``GeneratedMatcher`` seriously**; the current synthetic envelope materially favors it in that range. Treat roughly **1,500–2,250** as a crossover zone and measure both.
- From roughly **2,250 routes onward**, Fused is the generally safer warm-latency/artifact choice, while Generated remains available only when representative application measurements prove an exception. Do not auto-select by route count.
- Around several thousand routes, especially **~5,000+**, evaluate **``ShardedMatcher``** when cold boot or loaded working set is a material constraint. Around **10,000+**, a Fused-versus-Sharded deployment benchmark is recommended.
- Rebuild route-cache artifacts after Webrick upgrades or route-definition changes; matcher artifact schemas are versioned and stale artifacts fail closed.
'''
rec_start = 'Recommendation\n--------------\n'
rec_pos = text.find(rec_start)
if rec_pos < 0:
    raise SystemExit('missing matcher recommendation section')
text = text[:rec_pos] + recommendation
path.write_text(text)


# Matcher-cache reference ----------------------------------------------------
path = Path('docs/reference/route-cache.rst')
text = path.read_text()
modes_start = 'Modes\n-----\n'
php_header = 'PHP API\n-------\n'
modes = '''Modes
-----

.. list-table:: Matcher-cache modes
   :header-rows: 1
   :widths: 14 16 25 45

   * - Matcher
     - Cache location
     - Measured role
     - Approximate guidance
   * - ``fused``
     - PHP file
     - **default/general production matcher**
     - Valid across the measured range; generally the safer warm-latency/artifact choice from roughly 2,250 routes onward.
   * - ``generated``
     - PHP file
     - **small-to-medium/simple-topology specialization**
     - Strong measured candidate through roughly 1,500 routes in the current synthetic envelope and near parity around 1,750–2,000; benchmark explicitly beyond that.
   * - ``sharded``
     - directory
     - **cold-boot / bounded-working-set specialization**
     - Evaluate once several-thousand-route cache boot or loaded state becomes material; particularly useful when workers touch only part of a large table.

Prefer an explicit ``matcher`` value in deployment tooling. Start from ``fused`` when the route topology has not been measured, but do not dismiss Generated for low-thousands simple route tables: the completed crossover study widened its evidence-based envelope substantially.

A practical starting guide is:

- **below ~1,000 routes:** Fused is the safe default; Generated is a strong benchmark candidate for simple/static/distinct route sets;
- **~1,000–1,500 routes:** benchmark Fused and Generated; the current synthetic envelope materially favored Generated;
- **~1,500–2,250 routes:** treat this as a crossover zone and benchmark both;
- **~2,250–5,000 routes:** normally prefer Fused; keep Generated only for a repeatable topology-specific win and begin considering Sharded if startup/working-set pressure appears;
- **~5,000–10,000 routes:** Fused for fully resident warm throughput, Sharded for lazy boot/residency; Generated is not a general choice;
- **10,000+ routes:** benchmark Fused and Sharded side by side.

Route count is not an automatic selector. Generated showed non-monotonic isolated results at some larger sizes, but at 5,000 routes the current envelope also exposed a severe generated-code cliff: about **69.001 µs** warm versus **1.745 µs** Fused, with a roughly **26.04 MB** cache artifact versus **9.76 MB** Fused. Sharded's final cached candidate-group memoization reduced its warm overhead substantially, bringing 5,000/10,000-route warm measurements to roughly **2.24/2.41 µs** while retaining lazy first-shard loading.

'''
text = replace_between(text, modes_start, php_header, modes + php_header)
text = replace_once(
    text,
    '- Use Fused by default at any route count; benchmark Generated mainly for small/simple corpora and begin evaluating Sharded around several thousand routes when startup/working-set cost becomes material.',
    '- Use Fused as the general default; benchmark Generated seriously for simple route sets into the low thousands, and evaluate Sharded around several thousand routes when startup/working-set cost becomes material.',
)
path.write_text(text)


# Top-level docs/readme consistency -----------------------------------------
path = Path('docs/index.rst')
text = path.read_text()
text = replace_once(
    text,
    '- Matchers: Fused as the general production default, Generated for measured small-route wins, and Sharded for large cold-start/working-set tradeoffs.',
    '- Matchers: Fused as the general production default, Generated for benchmark-proven simple-route wins into the low thousands, and Sharded for large cold-start/working-set tradeoffs.',
)
path.write_text(text)

path = Path('README.md')
text = path.read_text()
text = replace_once(
    text,
    '- Static-first generated routing with fused and sharded alternatives.',
    '- Three measured matcher modes: Fused as the general default, Generated as a low-thousands/simple-topology specialization, and Sharded for lazy cache residency.',
)
path.write_text(text)


# Remove placeholder-only recipes from the advertised recipe set ------------
path = Path('docs/recipes/index.rst')
text = path.read_text()
for line in [
    '- `Webhooks <./webhooks.rst>`__ - Receive and validate webhook payloads\n',
    '- `Pagination <./pagination.rst>`__ - Efficient data pagination patterns\n',
    '- `Search with Filters <./search.rst>`__ - Build flexible search endpoints\n',
    '- `Testing <./testing.rst>`__ - Unit and integration testing strategies\n',
    '   pagination\n',
    '   search\n',
    '   testing\n',
    '   webhooks\n',
]:
    if line not in text:
        raise SystemExit(f'missing recipe index line: {line!r}')
    text = text.replace(line, '', 1)
path.write_text(text)

for file in [
    'docs/recipes/pagination.rst',
    'docs/recipes/search.rst',
    'docs/recipes/testing.rst',
    'docs/recipes/webhooks.rst',
    'MATCHER_OPTIMIZATION_PLAN.md',
]:
    candidate = Path(file)
    if not candidate.exists():
        raise SystemExit(f'missing cleanup candidate: {file}')
    candidate.unlink()
