
GPIO_DIR=/var/www/gpio
WWW_DIR=/var/www
ECHO=/bin/echo
KILL=/bin/kill
I2CSET=/usr/sbin/i2cset

PCA9685_BUS=1
RELAY_POWER=15
RELAY_POWER_FILE=relay_power.state
SCRIPT_FILENAME=script.sh

STATUS=$1
TIME=$2

CONF_FILE_NAME=relay_setup.conf

CONF_NAME1=relay_parter_wschod
CONF_NAME2=relay_parter_zachod
CONF_NAME3=relay_parter


####

F_EXEC () {                                    # Func DEBUG_WHILE_1

        TYPE=relay_parter_lazienka
        sh ${WWW_DIR}/${SCRIPT_FILENAME} ${TYPE} ${STATUS} ${TIME}> /dev/null 2>/dev/null &

#       TYPE=relay_parter_ogrod
#       sh ${WWW_DIR}/${SCRIPT_FILENAME} ${TYPE} ${STATUS} ${TIME}> /dev/null 2>/dev/null &

        TYPE=relay_parter_mama
        sh ${WWW_DIR}/${SCRIPT_FILENAME} ${TYPE} ${STATUS} ${TIME}> /dev/null 2>/dev/null &

	sleep 20
		
        TYPE=relay_parter_wejscie_okno
        sh ${WWW_DIR}/${SCRIPT_FILENAME} ${TYPE} ${STATUS} ${TIME}> /dev/null 2>/dev/null &

#       TYPE=relay_parter_wejscie_glowne
#       sh ${WWW_DIR}/${SCRIPT_FILENAME} ${TYPE} ${STATUS} ${TIME}> /dev/null 2>/dev/null &

        TYPE=relay_parter_komputerowka
        sh ${WWW_DIR}/${SCRIPT_FILENAME} ${TYPE} ${STATUS} ${TIME}> /dev/null 2>/dev/null &
		
}


#####

case "$1" in
  open)
        ${ECHO} "Open ..."

	#F_EXEC

	${ECHO} 1 > ${GPIO_DIR}/${CONF_NAME1}.state
	${ECHO} 1 > ${GPIO_DIR}/${CONF_NAME2}.state
	${ECHO} 1 > ${GPIO_DIR}/${CONF_NAME3}.state

	F_EXEC

        ;;
  close)
        ${ECHO} "Close ..."

	#F_EXEC

	${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME1}.state
	${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME2}.state
	${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME3}.state

	F_EXEC

	;;
  stop)
        ${ECHO} "Stop ..."

	#F_EXEC

	${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME1}.state
	${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME2}.state
	${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME3}.state

	F_EXEC

        ;;
  *)
    echo "Usage: {start|stop}"
    exit 1
    ;;
esac

exit 0


