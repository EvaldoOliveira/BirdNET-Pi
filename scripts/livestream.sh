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

# Stream bitrate: 320 kb/s for a mono microphone only cost CPU (and caused underruns while the analysis ran);
# 128 kb/s mono / 192 kb/s stereo by default, LIVESTREAM_BITRATE (e.g. 96k) overrides it
if [ -n "${LIVESTREAM_BITRATE}" ]; then
  BITRATE=${LIVESTREAM_BITRATE}
elif [ "${CHANNELS:-1}" -gt 1 ]; then
  BITRATE=192k
else
  BITRATE=128k
fi

if [ "$ACTIVATE_FREQSHIFT_IN_LIVESTREAM" == "true" ]; then
  FREQSHIFT_OPT='-af rubberband=pitch='${FREQSHIFT_LO}'/'${FREQSHIFT_HI}
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

  # TCP keeps an RTSP camera stream from losing packets on a busy Wi-Fi
  ffmpeg -nostdin -loglevel $LOGGING_LEVEL -rtsp_transport tcp -ac ${CHANNELS} -i ${SELECTED_RSTP_STREAM} -acodec libmp3lame \
    -b:a ${BITRATE} -ac ${CHANNELS} -content_type 'audio/mpeg' \
    ${FREQSHIFT_OPT} \
    -f mp3 icecast://source:${ICE_PWD}@localhost:8000/stream
else
  # -thread_queue_size buffers the microphone so short CPU peaks (an analysis run, a spectrogram) do not drop
  # audio; wall-clock timestamps keep them increasing on a shared (dsnoop) microphone, whose own timestamps
  # jump back and flooded the log with "non monotonically increasing dts"; the trailing -re of the old
  # command line was an input option ffmpeg ignored there
  ffmpeg -nostdin -loglevel $LOGGING_LEVEL -ac ${CHANNELS} -thread_queue_size 2048 -use_wallclock_as_timestamps 1 \
    -f alsa -i ${REC_CARD} -af aresample=async=1 -acodec libmp3lame \
    -b:a ${BITRATE} -ac ${CHANNELS} -content_type 'audio/mpeg' \
    ${FREQSHIFT_OPT} \
    -f mp3 icecast://source:${ICE_PWD}@localhost:8000/stream
fi
