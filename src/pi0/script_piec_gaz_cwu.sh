#!/bin/sh

GPIO_DIR=${GPIO_DIR:-/var/www/gpio}
WWW_DIR=${WWW_DIR:-/var/www}
ECHO=/bin/echo
KILL=/bin/kill

CONF_NAME=relay_piec_gaz_cwu
STATUS=$1

RELAY_POWER=519
RELAY=536    # Przelacza wlacznik na piecu gazowym BL - czyli zezwala na prace kotla nie zwiera opcji CO czy CWU
TIME=${2:-160}
RELAY_AFRISO=526
RELAY_AFRISO_DIRECTION=527

# Obsługa wywołań:
# sh script_piec_gaz_cwu.sh open [time]
# sh script_piec_gaz_cwu.sh close [time]


####

F_RELAY_POWER_ON () {
    # Wlaczenie Zasilanie
    ${ECHO} 0 > "${GPIO_DIR}/gpio${RELAY_POWER}" 
}

F_RELAY_POWER_OFF () {
    # Wylaczenie Zasilanie
    ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY_POWER}"
}

F_KILL_PROCESS () {

        TMP_PID=`ps -ax|grep -v grep | grep "${CONF_NAME} open" | cut -d" " -f1`
        if [ ! -z $TMP_PID ]; then
                ${KILL} -9 $TMP_PID
        fi

        TMP_PID=`ps -ax|grep -v grep | grep "${CONF_NAME} close" | cut -d" " -f1`
        if [ ! -z $TMP_PID ]; then
                ${KILL} -9 $TMP_PID
        fi        

}

#####

case "$STATUS" in
  open)
    ${ECHO} "Open ..."

    ${ECHO} 1 > ${GPIO_DIR}/${CONF_NAME}.state

    # Wlaczenie zasilania dla pieca gazowego 230
    F_RELAY_POWER_ON

    # Wlaczenie pieca gazowego na zworce BL
    ${ECHO} 0 > "${GPIO_DIR}/gpio${RELAY}"

    # Wlaczenie na przekazniku zasilania dla silownika i domyslnie obrot w strone GWC
    ${ECHO} 0 > "${GPIO_DIR}/gpio${RELAY_AFRISO}"
    ${ECHO} 0 > "${GPIO_DIR}/gpio${RELAY_AFRISO_DIRECTION}"
    
    sleep ${TIME}

    # Wylaczenie przekaznikow po wykonaniu operacji
    ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY_AFRISO}"
    ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY_AFRISO_DIRECTION}"

    ;;
  close)
    ${ECHO} "Close ..."

    ${ECHO} 0 > ${GPIO_DIR}/${CONF_NAME}.state

    # Wlaczenie pieca gazowego na zworce BL
    ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY}"

    # Wlaczenie na przekazniku zasilania dla silownika i domyslnie obrot w strone GWC
    ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY_AFRISO}"
    ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY_AFRISO_DIRECTION}"

    ;;
  stop)
    ${ECHO} "Stop Power off ..."

    # Ubija wszystkie procesy open lub close
    F_KILL_PROCESS

    ${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME}.state

    # Wlaczenie pieca gazowego na zworce BL
    ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY}"

    # Wlaczenie na przekazniku zasilania dla silownika i domyslnie obrot w strone GWC
    ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY_AFRISO}"
    ${ECHO} 1 > "${GPIO_DIR}/gpio${RELAY_AFRISO_DIRECTION}"

    # Czeka az piec ostygnie i go wylacza - palniki i elektronika musi ostygnac
    sleep 200

    # Wylaczenie zasilania dla pieca gazowego 230
    F_RELAY_POWER_OFF

    ;;
  *)
    echo "Usage: $0 [nazwa] {open|close|stop}"
    exit 1
    ;;
esac

exit 0
