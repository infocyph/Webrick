#!/usr/bin/env python3
"""Choose disjoint *physical* CPU cores for load clients and benchmark servers."""

import os
import sys
from pathlib import Path


def split_cores(topology):
    """Separate complete sibling sets, refusing overlap or a single real core."""
    groups = {}
    for cpu, core in topology.items():
        groups.setdefault(core, []).append(cpu)
    cores = sorted(groups)
    if len(cores) < 2:
        raise ValueError("CPU isolation requires at least two distinct physical cores")
    client_cpus = sorted(groups[cores[-1]])
    server_cpus = sorted(cpu for core in cores[:-1] for cpu in groups[core])
    if not server_cpus or not client_cpus or set(server_cpus) & set(client_cpus):
        raise ValueError("Server and client CPU sets must not overlap")
    return server_cpus, client_cpus


def live_topology():
    allowed = sorted(os.sched_getaffinity(0))
    if len(allowed) < 4:
        raise ValueError("Benchmark needs at least four schedulable logical CPUs")
    topology = {}
    for cpu in allowed:
        path = Path("/sys/devices/system/cpu") / f"cpu{cpu}" / "topology"
        package = int((path / "physical_package_id").read_text(encoding="ascii").strip())
        core = int((path / "core_id").read_text(encoding="ascii").strip())
        topology[cpu] = (package, core)
    return topology


def self_test():
    siblings = {0: (0, 0), 1: (0, 1), 2: (0, 0), 3: (0, 1)}
    assert split_cores(siblings) == ([0, 2], [1, 3])
    two_sockets = {0: (0, 0), 1: (1, 0), 2: (0, 1), 3: (1, 1)}
    server, client = split_cores(two_sockets)
    assert set(server).isdisjoint(client)
    assert server == [0, 2, 1] or set(server) == {0, 1, 2}
    assert client == [3]
    try:
        split_cores({0: (0, 0), 1: (0, 0)})
    except ValueError:
        pass
    else:
        raise AssertionError("Single-core topology was accepted")
    print("Physical-core sibling grouping and fail-closed isolation verified.")


def main():
    if sys.argv[1:] == ["--self-test"]:
        self_test()
        return

    if len(sys.argv) != 3 or sys.argv[1] != "--output":
        raise SystemExit("Usage: benchmark-cpu-topology.py --output <affinity evidence file>")
    topology = live_topology()
    servers, clients = split_cores(topology)
    settings = {
        "CERT_SERVER_CPUS": ",".join(map(str, servers)),
        "CERT_LOAD_CPUS": ",".join(map(str, clients)),
    }
    github_env = os.environ.get("GITHUB_ENV")
    if not github_env:
        raise SystemExit("GITHUB_ENV is required by hosted certification")
    with open(github_env, "a", encoding="utf-8") as env:
        for key, value in settings.items():
            env.write(f"{key}={value}\n")

    output = Path(sys.argv[2])
    with output.open("w", encoding="utf-8") as evidence:
        evidence.write(f"allowed_cpu_set={','.join(map(str, sorted(topology)))}\n")
        evidence.write(f"physical_core_groups={len(set(topology.values()))}\n")
        for cpu, core in sorted(topology.items()):
            evidence.write(f"cpu{cpu}_socket_core={core[0]},{core[1]}\n")
        for key, value in settings.items():
            evidence.write(f"{key}={value}\n")
    print(
        "Isolated physical-core benchmark: "
        f"server={settings['CERT_SERVER_CPUS']}, load={settings['CERT_LOAD_CPUS']}"
    )


if __name__ == "__main__":
    main()
