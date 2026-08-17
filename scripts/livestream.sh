#!/usr/bin/env bash
# Live Audio Stream Service Script
source /etc/birdnet/birdnet.conf

# Read the logging level from the configuration option
LOGGING_LEVEL="${LogLevel_LiveAudioStreamService}"
# If empty for some reason default to log level of error
[ -z $LOGGING_LEVEL ] && LOGGING_LEVEL='error'
# Additionally if we're at debug or info level then allow printing of script commands and variables
if [ "$LOGGING_LEVEL" == "info" ] || [ "$LOGGING_LEVEL" == "debug" ];then
  # Enable printing of commands/variables etc to terminal for debugging
  set -x
fi

if [ "$ACTIVATE_FREQSHIFT_IN_LIVESTREAM" == "true" ]; then
  FREQSHIFT_OPT='-af rubberband=pitch='${FREQSHIFT_LO}'/'${FREQSHIFT_HI}
fi

# UDP push source (T-SIM7080G / ESP32-C6 mic node in live mode): the node
# pushes continuous 48 kHz s16le mono PCM to UDP_LIVESTREAM_PORT while a live
# audio session is open (Bird Up! drawer player -> avian/api/live-audio.php).
# In burst mode the node does not send here, so this service is started/stopped
# by the live-audio hook rather than running 24/7.
if [ -n "${UDP_LIVESTREAM_PORT}" ];then
  ffmpeg -nostdin -loglevel $LOGGING_LEVEL -f s16le -ar 48000 -ac 1 -i udp://0.0.0.0:${UDP_LIVESTREAM_PORT} \
    -acodec libmp3lame -b:a 320k -ac 1 -content_type 'audio/mpeg' \
    ${FREQSHIFT_OPT} \
    -f mp3 icecast://source:${ICE_PWD}@localhost:8000/stream -re
  exit
fi

if [ -z ${REC_CARD} ];then
  echo "Stream not supported"
elif [[ ! -z ${RTSP_STREAM} ]];then
  # Explode the RSPT steam setting into an array so we can count the number we have
  RSTP_STREAMS_EXPLODED_ARRAY=(${RTSP_STREAM//,/ })

  # If for some reason the RTSP_STREAM_TO_LIVESTREAM is not set, then init it to 0 to use the first stream
  if [[ -z ${RTSP_STREAM_TO_LIVESTREAM} ]];then
    RTSP_STREAM_TO_LIVESTREAM=0
  fi

  # Get the RSTP stream at the specified array index
  SELECTED_RSTP_STREAM=${RSTP_STREAMS_EXPLODED_ARRAY[RTSP_STREAM_TO_LIVESTREAM]}

  # If for some reason the RTSP stream url is null
  if [[ -z ${SELECTED_RSTP_STREAM} ]];then
    # Try select the first stream
    SELECTED_RSTP_STREAM=${RSTP_STREAMS_EXPLODED_ARRAY[0]}
  fi

  ffmpeg -nostdin -loglevel $LOGGING_LEVEL -ac ${CHANNELS} -i ${SELECTED_RSTP_STREAM} -acodec libmp3lame \
    -b:a 320k -ac ${CHANNELS} -content_type 'audio/mpeg' \
    ${FREQSHIFT_OPT} \
    -f mp3 icecast://source:${ICE_PWD}@localhost:8000/stream -re
else
	ffmpeg -nostdin -loglevel $LOGGING_LEVEL -ac ${CHANNELS} -f alsa -i ${REC_CARD} -acodec libmp3lame \
    -b:a 320k -ac ${CHANNELS} -content_type 'audio/mpeg' \
    ${FREQSHIFT_OPT} \
    -f mp3 icecast://source:${ICE_PWD}@localhost:8000/stream -re
fi
