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

    # Wlaczenie pompy obiegowej
    ${ECHO} 0 > "${GPIO_DIR}/gpio${RELAY}"

    sleep ${TIME}

    # Wylaczenie pompy obiegowej
    ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY}"

    ;;
  close)
    ${ECHO} "Close ..."

    ${ECHO} 0 > ${GPIO_DIR}/${CONF_NAME}.state

    # Ubicie niepotrzebnego procesu ktory moze odliczac
    F_KILL_PROCESS_OPEN

    # Wylaczenie pompy obiegowej
    ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY}"

    ;;
  stop)
    ${ECHO} "Stop ..."

    ${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME}.state

    F_KILL_PROCESS_OPEN

    ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY}"

    ;;
  *)
    echo "Usage: $0 [nazwa] {open|close|stop}"
    exit 1
    ;;
esac

exit 0
