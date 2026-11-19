#!/usr/bin/env python3
"""Count real wrk connections; the banner reports the requested count even when rounded."""
import pathlib
import select
import socket
import subprocess
import sys
import time

helper = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else pathlib.Path(__file__).with_name('load-concurrency.sh')

def configured_threads(concurrency, workload="static"):
    return subprocess.run(['bash', '-c', 'source "$1"; load_threads "$2" "$3"',
                           'concurrency-test', str(helper.resolve()), str(concurrency), workload],
                          capture_output=True, text=True, check=False)

def observed_connections(concurrency, threads):
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        listener.listen(128)
        listener.setblocking(False)
        port = listener.getsockname()[1]
        process = subprocess.Popen(['wrk', '-t' + str(threads), '-c' + str(concurrency),
                                    '-d1s', '--timeout', '2s', 'http://127.0.0.1:' + str(port)],
                                   stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        clients = []
        requested = set()
        try:
            deadline = time.monotonic() + 5
            while process.poll() is None and time.monotonic() < deadline:
                readable, _, _ = select.select([listener] + clients, [], [], .05)
                for ready in readable:
                    if ready is listener:
                        client, _ = listener.accept()
                        client.setblocking(False)
                        clients.append(client)
                    else:
                        data = ready.recv(4096)
                        if data:
                            requested.add(ready)
                        else:
                            clients.remove(ready)
                            ready.close()
            output, _ = process.communicate(timeout=1)
            if process.returncode != 0:
                raise RuntimeError(output)
            return len(requested)
        finally:
            if process.poll() is None:
                process.kill()
                process.wait()
            for client in clients:
                client.close()

profiles = [('static', count) for count in [1, 5, 20, 50]]
profiles += [('risk_login', 20), ('risk_login_invalid', 50), ('risk_provider_slow', 5)]
for workload, concurrency in profiles:
    result = configured_threads(concurrency, workload)
    if result.returncode != 0:
        raise RuntimeError(result.stderr)
    threads = int(result.stdout.strip())
    if workload.startswith('risk_') and threads != 1:
        raise RuntimeError('CPU-bound samples require one client loop with every connection retained')
    observed = observed_connections(concurrency, threads)
    if observed != concurrency:
        raise RuntimeError(f'c{concurrency}: expected {concurrency} real connections, observed {observed} with {threads} threads')
    print(f'{workload}/c{concurrency}: {observed} real connections using {threads} threads')

for invalid in ['0', '-1', 'invalid', '2+2']:
    if configured_threads(invalid).returncode == 0:
        raise RuntimeError('Accepted unsupported concurrency: ' + invalid)
