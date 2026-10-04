#!/usr/bin/env python3

import asyncio
import ssl
import sys
from typing import Dict, Tuple

from aioquic.asyncio.client import connect
from aioquic.asyncio.protocol import QuicConnectionProtocol
from aioquic.h3.connection import H3_ALPN, H3Connection
from aioquic.h3.events import DataReceived, HeadersReceived
from aioquic.quic.configuration import QuicConfiguration
from aioquic.quic.events import QuicEvent


class CertificationHttp3Client(QuicConnectionProtocol):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._http = H3Connection(self._quic)
        self._responses: Dict[int, Tuple[asyncio.Future, bytearray, int | None]] = {}

    async def get(self, authority: str, path: str) -> tuple[int, bytes]:
        stream_id = self._quic.get_next_available_stream_id()
        future = asyncio.get_running_loop().create_future()
        self._responses[stream_id] = (future, bytearray(), None)
        self._http.send_headers(
            stream_id=stream_id,
            headers=[
                (b":method", b"GET"),
                (b":scheme", b"https"),
                (b":authority", authority.encode("ascii")),
                (b":path", path.encode("ascii")),
                (b"user-agent", b"webrick-http3-certification/1"),
            ],
            end_stream=True,
        )
        self.transmit()

        return await asyncio.wait_for(future, timeout=8.0)

    def quic_event_received(self, event: QuicEvent) -> None:
        for http_event in self._http.handle_event(event):
            if isinstance(http_event, HeadersReceived):
                current = self._responses.get(http_event.stream_id)
                if current is None:
                    continue
                future, body, status = current
                for name, value in http_event.headers:
                    if name == b":status":
                        status = int(value)
                self._responses[http_event.stream_id] = (future, body, status)
                if http_event.stream_ended:
                    self._complete(http_event.stream_id)
            elif isinstance(http_event, DataReceived):
                current = self._responses.get(http_event.stream_id)
                if current is None:
                    continue
                future, body, status = current
                body.extend(http_event.data)
                self._responses[http_event.stream_id] = (future, body, status)
                if http_event.stream_ended:
                    self._complete(http_event.stream_id)

    def _complete(self, stream_id: int) -> None:
        current = self._responses.pop(stream_id, None)
        if current is None:
            return

        future, body, status = current
        if future.done():
            return
        if status is None:
            future.set_exception(RuntimeError("HTTP/3 response did not contain :status."))

            return

        future.set_result((status, bytes(body)))


async def main(port: int, path: str, count: int) -> None:
    if count < 1 or count > 2_000:
        raise ValueError("count must be between 1 and 2000")

    configuration = QuicConfiguration(is_client=True, alpn_protocols=H3_ALPN)
    configuration.verify_mode = ssl.CERT_NONE
    configuration.server_name = "localhost"

    async with connect(
        "127.0.0.1",
        port,
        configuration=configuration,
        create_protocol=CertificationHttp3Client,
    ) as protocol:
        if not isinstance(protocol, CertificationHttp3Client):
            raise RuntimeError("aioquic returned an unexpected protocol implementation.")

        authority = f"localhost:{port}"
        body = b""
        for _ in range(count):
            status, body = await protocol.get(authority, path)
            if status != 200:
                raise RuntimeError(f"Runwire returned HTTP status {status} for {path}.")

    sys.stdout.buffer.write(body)


if __name__ == "__main__":
    if len(sys.argv) not in (3, 4):
        raise SystemExit("Usage: http3-client.py <port> <path> [count]")

    asyncio.run(main(int(sys.argv[1]), sys.argv[2], int(sys.argv[3]) if len(sys.argv) == 4 else 1))
