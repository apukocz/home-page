#!/bin/sh

GPIO_DIR=${GPIO_DIR:-/var/www/gpio}
WWW_DIR=${WWW_DIR:-/var/www}
ECHO=/bin/echo
KILL=/bin/kill
I2CSET=/usr/sbin/i2cset

CONF_NAME=$1
STATUS=$2
RELAY=$3
TIME=${4:-0.5}

# Logowanie każdego wywołania do pliku dla pełnej widoczności w systemie
LOG_FILE="${GPIO_DIR}/script_brama.log"
${ECHO} "$(date '+%Y-%m-%d %H:%M:%S') [PID: $$] sh script_brama.sh ${CONF_NAME} ${STATUS} ${RELAY} ${TIME}" >> "${LOG_FILE}" 2>/dev/null

# sh script_brama.sh relay_brama_wjazdowa open 530 0.5 # opcjonalnie
# sh script_brama.sh relay_brama_garazowa open 527 0.5

####

F_EXEC () {

  ${ECHO} 0 > ${GPIO_DIR}/gpio${RELAY}
  sleep ${TIME}
  ${ECHO} 1 > ${GPIO_DIR}/gpio${RELAY}

}


#####

case "$2" in
  open)
  ${ECHO} "Open ..."

  ${ECHO} 1 > ${GPIO_DIR}/${CONF_NAME}.state

  F_EXEC

  ;;
  close)
    ${ECHO} "Close ..."

    ${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME}.state

    F_EXEC

	;;
  stop)
    ${ECHO} "Stop ..."

    ${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME}.state

    F_EXEC

  ;;
  *)
    echo "Usage: $0 [nazwa] {open|close|stop} [relay] [time]"
    exit 1
    ;;
esac

exit 0