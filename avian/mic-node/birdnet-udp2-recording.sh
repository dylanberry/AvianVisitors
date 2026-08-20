#!/usr/bin/env bash
# BirdNET-Pi UDP2 recording for the push (burst/live) mic node.
#
# The T-SIM7080G node PUSHES 48 kHz s16le mono PCM over UDP instead of being
# pulled over RTSP. This service listens on UDP2_STREAM_PORT (default 8555)
# and writes the same %F-birdnet-*.wav segments into StreamData that the RTSP
# path produces, so birdnet_analysis consumes them unchanged.
#
# Burst-mode gaps: ffmpeg uses a UDP read timeout (UDP_READ_TIMEOUT_US,
# default 10 min) instead of blocking on the socket forever. Without it, a
# node that dies mid-segment (power loss, OTA reboot) leaves the segment file
# open and header-unfinalized indefinitely: no IN_CLOSE_WRITE fires and
# birdnet_analysis excludes open files from its backlog, so the partial WAV
# sits in StreamData forever as an orphan (and the NEXT burst would append
# hours-later audio to the stale segment). With the timeout, ffmpeg exits on
# a dead stream, the segment muxer finalizes the WAV header on the way out,
# the file flows into analysis as normal (short/truncated audio is fine), and
# loop_ffmpeg restarts a fresh listener. Churn is one respawn per silent
# interval - negligible.
source /etc/birdnet/birdnet.conf

# Read the logging level from the configuration option
LOGGING_LEVEL="${LogLevel_BirdnetRecordingService}"
[ -z "$LOGGING_LEVEL" ] && LOGGING_LEVEL='error'
if [ "$LOGGING_LEVEL" == "info" ] || [ "$LOGGING_LEVEL" == "debug" ]; then
  set -x
fi

PORT="${UDP2_STREAM_PORT:-8555}"
UDP_READ_TIMEOUT_US="${UDP_READ_TIMEOUT_US:-600000000}"   # 10 min
[ -z "$RECORDING_LENGTH" ] && RECORDING_LENGTH=15
[ -d "$RECS_DIR/StreamData" ] || mkdir -p "$RECS_DIR/StreamData"

loop_ffmpeg() {
  while true; do
    if ! ffmpeg -hide_banner -loglevel "$LOGGING_LEVEL" -nostdin \
        -f s16le -ar 48000 -ac 1 -i "udp://0.0.0.0:${PORT}?timeout=${UDP_READ_TIMEOUT_US}" \
        -vn -map a:0 -acodec pcm_s16le -ac 1 -ar 48000 \
        -f segment -segment_format wav -segment_time "${RECORDING_LENGTH}" -strftime 1 \
        "${RECS_DIR}/StreamData/%F-birdnet-UDP2-%H:%M:%S.wav"
    then
      sleep 1
    fi
  done
}

loop_ffmpeg
