#!/usr/bin/env python3
"""Combine isolated concurrency shards without weakening the full-matrix analyzer."""
import argparse
import json
import pathlib

CONCURRENCIES = (1, 5, 20, 50)


def merge_shards(root, output, mode, candidate_sha):
    expected = {f"webrick-http-{mode}-c{count}" for count in CONCURRENCIES}
    if {entry.name for entry in root.iterdir()} != expected:
        raise ValueError("Expected exactly four concurrency shard directories")

    common_environment = None
    rows = []
    for count in CONCURRENCIES:
        shard = root / f"webrick-http-{mode}-c{count}"
        environment = (shard / "environment.txt").read_text().splitlines()
        for prefix in ("baseline_sha=", "candidate_sha=", "php_version=", "benchmark_mode=",
                       "benchmark_concurrency=", "infocyph/runwire ", "infocyph/intermix "):
            matching = [line for line in environment if line.startswith(prefix)]
            if len(matching) != 1 or len(matching[0]) == len(prefix):
                raise ValueError("Required shard provenance is missing, empty or duplicated")
        if environment.count(f"candidate_sha={candidate_sha}") != 1:
            raise ValueError("Shard candidate revision does not match the workflow")
        if environment.count(f"benchmark_mode={mode}") != 1:
            raise ValueError("Shard runtime does not match the requested mode")
        if environment.count(f"benchmark_concurrency={count}") != 1:
            raise ValueError("Shard concurrency provenance is missing or incorrect")
        shared = [line for line in environment if not line.startswith("benchmark_concurrency=")]
        if common_environment is None:
            common_environment = shared
        elif shared != common_environment:
            raise ValueError("Shard baseline, candidate or runtime dependency provenance differs")

        lines = (shard / "load-results.jsonl").read_text().splitlines()
        if not lines:
            raise ValueError("Shard measurement evidence is empty")
        for line in lines:
            row = json.loads(line)
            if not isinstance(row, dict) or row.get("mode") != mode:
                raise ValueError("Shard contains evidence for another runtime")
            if type(row.get("concurrency")) is not int or row["concurrency"] != count:
                raise ValueError("Shard contains evidence for another concurrency")
            rows.append(line)

    output.mkdir(parents=True, exist_ok=True)
    (output / "load-results.jsonl").write_text("\n".join(rows) + "\n")
    (output / "environment.txt").write_text("\n".join(common_environment) + "\n")
    print(f"Merged {len(rows)} rows from all four {mode} shards; full analyzer validation is required")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("root", type=pathlib.Path)
    parser.add_argument("output", type=pathlib.Path)
    parser.add_argument("mode", choices=("sapi", "runwire"))
    parser.add_argument("candidate_sha")
    args = parser.parse_args()
    merge_shards(args.root, args.output, args.mode, args.candidate_sha)
