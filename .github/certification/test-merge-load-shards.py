#!/usr/bin/env python3
"""Exercise provenance and complete-matrix rejection with the real analyzer."""
import importlib.util
import json
import pathlib
import subprocess
import tempfile
import unittest

DIRECTORY = pathlib.Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("merge_shards", DIRECTORY / "merge-load-shards.py")
MERGER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MERGER)
WORKLOADS = ("static", "dynamic", "json", "stream", "upload", "not_found", "method_not_allowed",
             "file", "range", "slow", "risk_allow", "risk_counter", "risk_clearance", "risk_login",
             "risk_login_invalid", "risk_provider_slow")


class ShardEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="webrick-shard-test-")
        self.addCleanup(self.directory.cleanup)
        self.root = pathlib.Path(self.directory.name) / "shards"
        self.root.mkdir()
        self.output = pathlib.Path(self.directory.name) / "merged"
        for count in MERGER.CONCURRENCIES:
            shard = self.root / f"webrick-http-runwire-c{count}"
            shard.mkdir()
            (shard / "environment.txt").write_text(
                f"baseline_sha=base\ncandidate_sha=head\nphp_version=8.5\n"
                f"benchmark_mode=runwire\nbenchmark_concurrency={count}\n"
                "infocyph/runwire baseline=3.0.1 candidate=3.0.1\n"
                "infocyph/intermix baseline=11.2.1 candidate=11.2.1\n")
            rows = []
            for workload in WORKLOADS:
                for version in ("baseline", "candidate"):
                    for trial in (1, 2, 3):
                        rows.append(json.dumps({
                            "mode": "runwire", "concurrency": count, "workload": workload,
                            "version": version, "trial": trial, "rps": 100, "p99": 1, "rss_kb": 1024,
                            "complete": 100, "validated": 100, "invalid": 0, "failed": 0,
                            "non2xx": 100 if workload in ("not_found", "method_not_allowed", "risk_login_invalid") else 0,
                            "socket_errors": {"connect": 0, "read": 0, "write": 0, "timeout": 0},
                            "metrics": {"pid": 1, "memory_current_bytes": 1024, "memory_peak_bytes": 1024,
                                        "requests_active": 1, "queued_bytes_current": 0, "rejected_requests_total": 0},
                        }))
            (shard / "load-results.jsonl").write_text("\n".join(rows) + "\n")

    def merge(self):
        MERGER.merge_shards(self.root, self.output, "runwire", "head")

    def analyze(self):
        return subprocess.run([
            "php", str(DIRECTORY / "analyze-load.php"), f"--input={self.output / 'load-results.jsonl'}",
            f"--summary={self.output / 'summary.md'}", f"--json={self.output / 'comparison.json'}",
            "--profile=runwire", "--trials=3",
        ], capture_output=True, text=True, check=False)

    def test_complete_matrix_passes(self):
        self.merge()
        result = self.analyze()
        self.assertEqual(0, result.returncode, result.stderr)
        data = json.loads((self.output / "comparison.json").read_text())
        self.assertEqual(384, data["observed_rows"])
        self.assertEqual(64, len(data["comparisons"]))

    def test_missing_shard_rejected(self):
        (self.root / "webrick-http-runwire-c50").rename(self.root / "wrong-shard")
        with self.assertRaises(ValueError):
            self.merge()

    def test_wrong_candidate_or_baseline_rejected(self):
        path = self.root / "webrick-http-runwire-c5" / "environment.txt"
        original = path.read_text()
        for old, new in (("candidate_sha=head", "candidate_sha=other"), ("baseline_sha=base", "baseline_sha=other"),
                         ("baseline=3.0.1", "baseline=3.0.2"), ("benchmark_concurrency=5", "benchmark_concurrency=1")):
            with self.subTest(new=new):
                path.write_text(original.replace(old, new))
                with self.assertRaises(ValueError):
                    self.merge()

    def test_misplaced_rows_rejected(self):
        path = self.root / "webrick-http-runwire-c5" / "load-results.jsonl"
        path.write_text(path.read_text().replace('"concurrency": 5', '"concurrency": 50'))
        with self.assertRaises(ValueError):
            self.merge()

    def test_missing_dependency_provenance_rejected(self):
        path = self.root / "webrick-http-runwire-c5" / "environment.txt"
        path.write_text(path.read_text().replace("infocyph/intermix baseline=11.2.1 candidate=11.2.1\n", ""))
        with self.assertRaises(ValueError):
            self.merge()

    def test_duplicate_revision_provenance_rejected(self):
        path = self.root / "webrick-http-runwire-c5" / "environment.txt"
        path.write_text(path.read_text() + "candidate_sha=other\n")
        with self.assertRaises(ValueError):
            self.merge()

    def test_missing_duplicate_or_regressed_rows_fail_full_gate(self):
        path = self.root / "webrick-http-runwire-c5" / "load-results.jsonl"
        original = path.read_text().splitlines()
        regressed = json.loads(original[3])
        regressed["rps"] = 90
        other_regressed = json.loads(original[4])
        other_regressed["rps"] = 90
        for changed in (original[1:], original + [original[0]],
                        original[:3] + [json.dumps(regressed), json.dumps(other_regressed)] + original[5:]):
            path.write_text("\n".join(changed) + "\n")
            self.merge()
            self.assertNotEqual(0, self.analyze().returncode)


if __name__ == "__main__":
    unittest.main()
