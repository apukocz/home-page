#!/bin/sh

GPIO_DIR=${GPIO_DIR:-/var/www/gpio}
WWW_DIR=${WWW_DIR:-/var/www}
ECHO=/bin/echo
KILL=/bin/kill

RELAY_POWER=519
RELAY=536

CONF_NAME=relay_piec_gaz
STATUS=$1

# Obsługa wywołań:
# sh script_piec_gaz.sh open
# sh script_piec_gaz.sh close
# sh script_piec_gaz.sh relay_piec_gaz open
if [ "$1" = "open" ] || [ "$1" = "close" ] || [ "$1" = "stop" ]; then
  STATUS=$1
elif [ "$2" = "open" ] || [ "$2" = "close" ] || [ "$2" = "stop" ]; then
  CONF_NAME=$1
  STATUS=$2
fi

####

F_RELAY_POWER_ON () {
    # Wlaczenie Zasilanie
    [ -e "${GPIO_DIR}/gpio${RELAY_POWER}" ] && ${ECHO} 0 > "${GPIO_DIR}/gpio${RELAY_POWER}" || ${ECHO} 0 > "${GPIO_DIR}/gpio${RELAY_POWER}" 2>/dev/null || true
}

F_RELAY_POWER_OFF () {
    # Wylaczenie Zasilanie
    [ -e "${GPIO_DIR}/gpio${RELAY_POWER}" ] && ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY_POWER}" || ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY_POWER}" 2>/dev/null || true
}


#####

case "$STATUS" in
  open)
    ${ECHO} "Open ..."

    ${ECHO} 1 > ${GPIO_DIR}/${CONF_NAME}.state
    [ "${CONF_NAME}" = "relay_piec_gaz" ] && ${ECHO} 1 > ${GPIO_DIR}/piec_co.state 2>/dev/null || true

    F_RELAY_POWER_ON
    [ -e "${GPIO_DIR}/gpio${RELAY}" ] && ${ECHO} 0 > "${GPIO_DIR}/gpio${RELAY}" || ${ECHO} 0 > "${GPIO_DIR}/gpio${RELAY}" 2>/dev/null || true

    ;;
  close)
    ${ECHO} "Close ..."

    ${ECHO} 0 > ${GPIO_DIR}/${CONF_NAME}.state
    [ "${CONF_NAME}" = "relay_piec_gaz" ] && ${ECHO} 0 > ${GPIO_DIR}/piec_co.state 2>/dev/null || true

    [ -e "${GPIO_DIR}/gpio${RELAY}" ] && ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY}" || ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY}" 2>/dev/null || true

    ;;
  stop)
    ${ECHO} "Stop ..."

    ${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME}.state
    [ "${CONF_NAME}" = "relay_piec_gaz" ] && ${ECHO} 2 > ${GPIO_DIR}/piec_co.state 2>/dev/null || true

    F_RELAY_POWER_OFF
    [ -e "${GPIO_DIR}/gpio${RELAY}" ] && ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY}" || ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY}" 2>/dev/null || true

    ;;
  *)
    echo "Usage: $0 [nazwa] {open|close|stop}"
    exit 1
    ;;
esac

exit 0
