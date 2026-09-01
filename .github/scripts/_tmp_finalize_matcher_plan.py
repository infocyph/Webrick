from __future__ import annotations

from pathlib import Path

PLAN = Path('MATCHER_OPTIMIZATION_PLAN.md')


def replace_once(text: str, old: str, new: str) -> str:
    if old not in text:
        raise SystemExit(f'missing plan marker: {old[:80]!r}')
    return text.replace(old, new, 1)


text = PLAN.read_text()
text = replace_once(
    text,
    'Status: active development plan — Phases 1–6 complete  ',
    'Status: complete — Phases 1–9 closed (2026-09-01)  ',
)

phase7 = '''## Phase 7 — Fused integration

**Status: complete (2026-09-01) — the retained shared compact IR was already the Fused production representation.**

Completion record:

- No parallel migration layer was needed. `FusedMatcher` already compiles `CanonicalMatcherIndex` through `CompiledMatcherIndexCompiler`, compacts with `CompiledMatcherIrCompactor`, validates with `CompactMatcherIndexValidator`, and dispatches through the shared compact engines.
- The Phase 3 constraint opcodes and Phase 5 single-shape method terminals therefore became Fused behavior at their original acceptance commits rather than waiting for a separate Phase 7 copy/integration step.
- Cache version `16`, deterministic route IDs, alias/middleware metadata, compact result contracts, cache hashes, and stale/corrupt artifact rejection remain intact. Phase 7 itself changed no persisted representation and therefore required no additional cache-version bump.
- The permanent `benchmark/matcher_envelope.php` profiler now records build time, artifact size, cold boot, first hit, warm hit, boot memory, first-hit memory, and build peak for all matcher modes in isolated processes.
- Full PHPForge validation passed before the integration envelope was accepted.

Representative PHP 8.4.25 cached-envelope results (opcache disabled):

| Routes | Build | Artifact | Cold boot | First hit | Warm hit | Boot memory |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1,000 | 70.54 ms | 2.00 MB | 12.69 ms | 29.95 µs | 1.560 µs | 4.47 MB |
| 5,000 | 378.32 ms | 9.76 MB | 69.78 ms | 33.65 µs | 1.708 µs | 21.74 MB |
| 10,000 | 1,046.32 ms | 24.05 MB | 173.51 ms | 43.13 µs | 1.866 µs | 52.40 MB |

The complete scale benchmark remains effectively flat: the final post-integration 10,000-route representative hit was about **2.04 µs**. Fused is therefore the broad low-latency default: it pays full cache boot/resident-memory cost to keep request-time latency nearly independent of route-table size.

Once the adaptive IR beats the current shared IR across the acceptance matrix:
'''
text = replace_once(text, '## Phase 7 — Fused integration\n\nOnce the adaptive IR beats the current shared IR across the acceptance matrix:\n', phase7)

phase8 = '''## Phase 8 — Sharded integration

**Status: complete (2026-09-01) — shared IR retained and cached candidate-group memoization landed.**

Completion record:

- Sharded persists and validates the same compact matcher group IR used by Fused; shard boundaries remain a host/prefix storage and working-set concern only.
- Generation publication, manifest selection, per-shard validation/hash checks, and atomic cache-generation behavior remain unchanged.
- Integration profiling exposed one real cached hot-path cost: an already-loaded shard still rebuilt/sanitized shard file paths and reconstructed its candidate-group list on every request.
- `ShardedMatcher` now memoizes the resolved candidate-group list per host/prefix. File-name sanitation and candidate-array construction therefore occur on first access to a shard rather than every warm request.
- The memoization changes only in-memory residency metadata, not persisted cache format, so cache version `16` remains correct.
- The candidate cleared a same-run gate requiring at least 20% warm improvement at 1k/5k/10k without more than 15% first-hit regression, then passed the complete PHPForge gate and outcome/scale regressions.

Same-run cached Sharded A/B medians:

| Routes | Warm baseline | Warm retained | Ratio | First baseline | First retained | Ratio |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1,000 | 5.277 µs | 2.082 µs | 0.395x | 166.4 µs | 169.5 µs | 1.019x |
| 5,000 | 5.348 µs | 2.237 µs | 0.418x | 458.1 µs | 431.3 µs | 0.941x |
| 10,000 | 5.633 µs | 2.405 µs | 0.427x | 983.3 µs | 988.8 µs | 1.006x |

The retained change cuts cached warm latency by roughly **57–61%** while first-hit latency stays neutral. Tested first-hit memory grows by only about **1 KB** for the memoized candidate list. A final isolated 10,000-route cache profile measured **57.23 µs cold boot**, **985.98 µs first hit**, **2.489 µs warm hit**, only **776 bytes boot-memory delta**, and **361,440 bytes first-hit memory**.

This preserves Sharded's intended envelope: dramatically cheaper cache boot and bounded loaded working set than Fused, with warm dispatch now much closer to the shared in-memory engine.

Apply the same adaptive group IR to Sharded.
'''
text = replace_once(text, '## Phase 8 — Sharded integration\n\nApply the same adaptive group IR to Sharded.\n', phase8)

phase9 = '''## Phase 9 — Generated matcher decision

**Status: complete (2026-09-01) — retain Generated as an explicit small-route specialization.**

Decision record:

- Generated still earns a separate public mode for small/simple route tables. It is materially faster than Fused in the low-thousands while accepting larger generated-code, cache-artifact, boot, and resident-memory costs.
- The measured advantage is clear through about **1,500 routes** in the synthetic cache envelope. Around **1,750–2,000 routes** it reaches near-parity, and from **2,250 routes onward Fused is generally the safer performance choice**.
- Generated is deliberately **not** auto-selected by route count. The generated PHP control-flow shape produces non-monotonic results (for example, isolated near-wins around 3.5k/4.5k in this synthetic family), so route count alone is not a stable selection heuristic.
- At 5,000 routes the generated representation crosses a severe code-size/execution cliff: median cached warm latency was **69.001 µs** versus Fused **1.745 µs** — about **39.6x slower**. The broader scale suite likewise places Generated around 100 µs at 5k/10k while Fused remains about 2 µs.
- Generated's artifact footprint is also much larger: at 5,000 routes about **26.04 MB** versus Fused **9.76 MB**; at 10,000 routes the earlier envelope measured about **52.11 MB** versus **24.05 MB**. Its build/cold-boot/resident-memory envelope grows correspondingly faster.
- No Generated removal is justified. Likewise, there is no benchmark justification for a fourth `MatcherModeEnum::ADAPTIVE` mode: the retained adaptive decisions belong inside the shared Fused/Sharded compiler/runtime.
- Generated may reuse compiler analysis metadata in a future measured optimization, but its generated-PHP runtime should remain decoupled from the compact IR unless that reuse demonstrates a concrete gain.

Crossover study, median cached warm latency from three isolated PHP 8.4.25 runs per point (opcache disabled):

| Routes | Fused | Generated | Generated / Fused |
| ---: | ---: | ---: | ---: |
| 1,000 | 1.606 µs | 0.772 µs | 0.481x |
| 1,250 | 1.607 µs | 1.038 µs | 0.646x |
| 1,500 | 1.738 µs | 0.979 µs | 0.563x |
| 1,750 | 1.567 µs | 1.507 µs | 0.962x |
| 2,000 | 1.623 µs | 1.575 µs | 0.970x |
| 2,250 | 1.626 µs | 1.776 µs | 1.092x |
| 2,500 | 1.673 µs | 1.920 µs | 1.147x |
| 2,750 | 1.624 µs | 1.965 µs | 1.210x |
| 3,000 | 1.662 µs | 2.175 µs | 1.309x |
| 3,500 | 1.680 µs | 1.609 µs | 0.958x |
| 4,000 | 1.762 µs | 2.016 µs | 1.144x |
| 4,500 | 1.742 µs | 1.740 µs | 0.999x |
| 5,000 | 1.745 µs | 69.001 µs | 39.550x |

Final matcher-mode guidance:

- **Generated** — explicit specialization for small/simple route sets where lowest warm-hit latency is worth larger generated artifacts and boot/memory cost.
- **Fused** — broad production default when consistently low request latency across medium/large route tables matters most.
- **Sharded** — same matching semantics/IR with lazy cache residency; prefer when cold boot and bounded loaded working set matter enough to accept a higher first-shard hit.

After adaptive Fused/Sharded stabilize, benchmark Generated again.
'''
text = replace_once(text, '## Phase 9 — Generated matcher decision\n\nAfter adaptive Fused/Sharded stabilize, benchmark Generated again.\n', phase9)

PLAN.write_text(text)
