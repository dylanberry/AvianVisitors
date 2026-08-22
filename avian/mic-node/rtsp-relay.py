#!/usr/bin/env python3
"""Bird Up! mic-node RTSP relay: multicast PCM -> continuous PCM on stdout.

Joins the birdup-fanout multicast group locally (wlan0, same host as the
socat sender) and emits 48 kHz mono s16le PCM on stdout, padding with digital
silence whenever the mic node is between dumps. The downstream ffmpeg (spawned
by mediamtx runOnInit) therefore always has frames to encode, so the RTSP
path /mic stays published continuously — mediamtx paths only exist while a
publisher is attached, and the cluster BirdNET-Go's probe needs the path up
even during silent gaps.

PCM zeros = silence; mic PCM passes through verbatim (no resample).
"""

import socket
import struct
import sys

GROUP = "224.0.0.100"
PORT = 8555
SILENCE = b"\x00\x00" * 4800  # 100 ms of 48 kHz mono s16le silence

s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
s.bind(("", PORT))
mreq = struct.pack("4s4s", socket.inet_aton(GROUP), socket.inet_aton("0.0.0.0"))
s.setsockopt(socket.IPPROTO_IP, socket.IP_ADD_MEMBERSHIP, mreq)
s.settimeout(0.2)

out = sys.stdout.buffer
while True:
    try:
        data, _ = s.recvfrom(4096)
        if data:
            out.write(data)
            out.flush()
    except socket.timeout:
        out.write(SILENCE)
        out.flush()