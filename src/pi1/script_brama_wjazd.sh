
GPIO_DIR=/var/www/gpio
WWW_DIR=/var/www
ECHO=/bin/echo
KILL=/bin/kill
I2CSET=/usr/sbin/i2cset

PCA9685_BUS=1
RELAY_POWER=15
RELAY_POWER_FILE=relay_power.state
SCRIPT_FILENAME=script_one.sh

STATUS=$1
TIME=$2

CONF_FILE_NAME=relay_setup.conf

CONF_NAME=relay_brama_wjazd

RELAY_POWER=15

####

F_EXEC () {                                    # Func DEBUG_WHILE_1

        TYPE=relay_brama_wjazd
        sh ${WWW_DIR}/${SCRIPT_FILENAME} ${TYPE} ${STATUS} ${TIME}> /dev/null 2>/dev/null &

}


#####

case "$1" in
  open)
        ${ECHO} "Open ..."

	#F_EXEC

	${ECHO} 1 > ${GPIO_DIR}/${CONF_NAME}.state

	#F_EXEC
	${ECHO} 0 > ${GPIO_DIR}/gpio${RELAY_POWER}
	sleep 1
	${ECHO} 1 > ${GPIO_DIR}/gpio${RELAY_POWER}

        ;;
  close)
        ${ECHO} "Close ..."

	#F_EXEC

	${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME}.state

	#F_EXEC
        ${ECHO} 0 > ${GPIO_DIR}/gpio${RELAY_POWER}
        sleep 1
        ${ECHO} 1 > ${GPIO_DIR}/gpio${RELAY_POWER}

	;;
  stop)
        ${ECHO} "Stop ..."

	#F_EXEC

	${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME}.state

	#F_EXEC
        ${ECHO} 0 > ${GPIO_DIR}/gpio${RELAY_POWER}
        sleep 1
        ${ECHO} 1 > ${GPIO_DIR}/gpio${RELAY_POWER}


        ;;
  *)
    echo "Usage: {start|stop}"
    exit 1
    ;;
esac

exit 0


