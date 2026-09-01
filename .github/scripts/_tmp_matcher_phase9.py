from __future__ import annotations

import json
import statistics
import sys
from collections import defaultdict
from pathlib import Path


def main() -> None:
    source = Path(sys.argv[1])
    rows: dict[tuple[int, str], list[dict[str, object]]] = defaultdict(list)
    for line in source.read_text().splitlines():
        line = line.strip()
        if not line.startswith('{'):
            continue
        data = json.loads(line)
        rows[(int(data['routes']), str(data['matcher']))].append(data)

    print('routes,fused_warm_ns,generated_warm_ns,generated_over_fused,fused_build_ms,generated_build_ms,fused_artifact,generated_artifact')
    for routes in sorted({key[0] for key in rows}):
        fused = rows[(routes, 'fused')]
        generated = rows[(routes, 'generated')]
        fused_warm = statistics.median(float(row['warm_ns']) for row in fused)
        generated_warm = statistics.median(float(row['warm_ns']) for row in generated)
        fused_build = statistics.median(float(row['build_ms']) for row in fused)
        generated_build = statistics.median(float(row['build_ms']) for row in generated)
        fused_artifact = int(statistics.median(int(row['artifact_bytes']) for row in fused))
        generated_artifact = int(statistics.median(int(row['artifact_bytes']) for row in generated))
        print(
            f'{routes},{fused_warm:.3f},{generated_warm:.3f},{generated_warm / fused_warm:.3f},'
            f'{fused_build:.3f},{generated_build:.3f},{fused_artifact},{generated_artifact}'
        )


if __name__ == '__main__':
    main()
