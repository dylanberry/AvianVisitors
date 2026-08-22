#!/bin/sh
# Bird Up! mic RTSP relay pipeline (single executable for mediamtx runOnInit,
# which does not run commands through a shell — the pipe lives here instead).
# rtsp-relay.py joins the fan-out multicast group locally and emits continuous
# 48k mono s16le PCM (silence-padded between dumps); ffmpeg encodes AAC and
# publishes RTSP to mediamtx's /mic path.
exec python3 /usr/local/bin/rtsp-relay.py | ffmpeg -hide_banner -loglevel error -nostdin -f s16le -ar 48000 -ac 1 -i pipe:0 -c:a aac -b:a 96k -f rtsp -rtsp_transport tcp rtsp://127.0.0.1:8554/mic
