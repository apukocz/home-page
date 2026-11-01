#!/bin/sh

GPIO_DIR=${GPIO_DIR:-/var/www/gpio}
WWW_DIR=${WWW_DIR:-/var/www}
ECHO=/bin/echo
KILL=/bin/kill

RELAY=535

CONF_NAME=relay_pompa_obiegowa
STATUS=$1
TIME=${2:-300}

# Obsługa wywołań:
# sh script_pompa_obieg.sh open
# sh script_pompa_obieg.sh close
# sh script_pompa_obieg.sh relay_pompa_obiegowa open
if [ "$2" = "open" ] || [ "$2" = "close" ] || [ "$2" = "stop" ]; then
  CONF_NAME=$1
  STATUS=$2
  TIME=${3:-300}
fi

F_KILL_PROCESS_OPEN () {

  TMP_PID=`ps -ax 2>/dev/null | grep -v grep | grep "${CONF_NAME} open" | awk '{print $1}'`
  if [ ! -z "$TMP_PID" ]; then
          ${KILL} -9 $TMP_PID 2>/dev/null || true
  fi

}


#####

case "$STATUS" in
  open)
    ${ECHO} "Open ..."

    ${ECHO} 1 > ${GPIO_DIR}/${CONF_NAME}.state

    [ -e "${GPIO_DIR}/gpio${RELAY}" ] && ${ECHO} 0 > "${GPIO_DIR}/gpio${RELAY}" || ${ECHO} 0 > "${GPIO_DIR}/gpio${RELAY}" 2>/dev/null || true

    ;;
  close)
    ${ECHO} "Close ..."

    ${ECHO} 0 > ${GPIO_DIR}/${CONF_NAME}.state

    F_KILL_PROCESS_OPEN

    [ -e "${GPIO_DIR}/gpio${RELAY}" ] && ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY}" || ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY}" 2>/dev/null || true

    ;;
  stop)
    ${ECHO} "Stop ..."

    ${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME}.state

    F_KILL_PROCESS_OPEN

    [ -e "${GPIO_DIR}/gpio${RELAY}" ] && ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY}" || ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY}" 2>/dev/null || true

    ;;
  *)
    echo "Usage: $0 [nazwa] {open|close|stop}"
    exit 1
    ;;
esac

exit 0
