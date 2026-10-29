#!/bin/sh

# todo
# power relay dla zasilacza 5v


GPIO_DIR=${GPIO_DIR:-/var/www/gpio}
ECHO=/bin/echo
KILL=/bin/kill
I2CSET=/usr/sbin/i2cset

PCA9685_BUS=1
RELAY_POWER=535
RELAY_POWER_FILE=relay_power.state

CONF_FILE_NAME=relay_setup.conf

CONF_NAME=$1
TYPE=$2
TIME=$3


CONF=`cat ${GPIO_DIR}/${CONF_FILE_NAME} | grep ${CONF_NAME}`

CONF_ADDRESS1=`${ECHO} ${CONF} | cut -d";" -f2`
CONF_RELAY11=`${ECHO} ${CONF} | cut -d";" -f3`
CONF_RELAY12=`${ECHO} ${CONF} | cut -d";" -f4`
CONF_OPEN11=`${ECHO} ${CONF} | cut -d";" -f5`
CONF_OPEN12=`${ECHO} ${CONF} | cut -d";" -f6`
CONF_STOP1=`${ECHO} ${CONF} | cut -d";" -f7`
CONF_ADDRESS2=`${ECHO} ${CONF} | cut -d";" -f8`
CONF_RELAY21=`${ECHO} ${CONF} | cut -d";" -f9`
CONF_RELAY22=`${ECHO} ${CONF} | cut -d";" -f10`
CONF_OPEN21=`${ECHO} ${CONF} | cut -d";" -f11`
CONF_OPEN22=`${ECHO} ${CONF} | cut -d";" -f12`
CONF_STOP21=`${ECHO} ${CONF} | cut -d";" -f13`
CONF_STOP22=`${ECHO} ${CONF} | cut -d";" -f14`
CONF_TIME=$(echo "${CONF}" | cut -d";" -f15)
CONF_STATUS=${CONF_NAME}.state

TMP=$1

# Jeseli jest parametr czasu to otwierac/zamykac zgodnie ztym czasem
if [ -z ${TIME} ]; then
	CONF_TIME=$(echo "${CONF}" | cut -d\; -f15)
else
	CONF_TIME=${TIME}

fi

echo "aaaaaaaaaa: ${CONF_TIME}"

#----
# i2c Setup
#${I2CSET} -y 1 0x40 0 0x00
#${I2CSET} -y 1 0x40 0 16
#${I2CSET} -y 1 0x40 254 101
#${I2CSET} -y 1 0x40 0 0x00

#${I2CSET} -y 1 0x41 0 0x00
#${I2CSET} -y 1 0x41 0 16
#${I2CSET} -y 1 0x41 254 101
#${I2CSET} -y 1 0x41 0 0x00

# restart
#${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} 0x01 0x91
#${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} 0x01 0x91

# set output logic as inverted
#${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} 0x01 0x10
#${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} 0x01 0x10

# change to normal mode
#${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} 0x01 0x01
#${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} 0x01 0x01
#----


####
F_RELAY_POWER_CHECK () {

        # Sprawdza czy zasilacz jest uzywany przez kogos innego
        STATE=`cat ${GPIO_DIR}/${RELAY_POWER_FILE}`

        # Jesli zamkniety otwiera aby pompa mogla grzac
        if [ ${STATE} = ${CONF_NAME} ]; then

                #${ECHO} 1 > ${GPIO_DIR}/gpio${GPIO_RELAY}/value
		${ECHO} 1 > ${GPIO_DIR}/gpio${RELAY_POWER}

        fi
}

F_RELAY_POWER_ON () {

        # Znacznik do zasilania o wlaczeniu aby nie zaklucac innych przelaczanych przekaznikow
        ${ECHO} ${CONF_NAME} > ${GPIO_DIR}/${RELAY_POWER_FILE}

	# Wlaczenie Zasilanie 5v
	${ECHO} 0 > ${GPIO_DIR}/gpio${RELAY_POWER}

}

F_RELAY_POWER_OFF () {

        # Wlaczenie Zasilanie 5v
	#sleep 5
        ${ECHO} 1 > ${GPIO_DIR}/gpio${RELAY_POWER}

}



F_KILL_PROCESS_OPEN () {

        TMP_PID=`ps -ax|grep -v grep | grep "${CONF_NAME} open" | cut -d" " -f1`
        if [ ! -z $TMP_PID ]; then
                ${KILL} -9 $TMP_PID
        fi

#        TMP_PID=`ps -ax|grep -v grep | grep "${CONF_NAME} close" | cut -d" " -f2`
#        ${KILL} -9 $TMP_PID

}

F_KILL_PROCESS_CLOSE () {

        TMP_PID=`ps -ax|grep -v grep | grep "${CONF_NAME} close" | cut -d" " -f1`
	if [ ! -z $TMP_PID ]; then
		${KILL} -9 $TMP_PID
        fi

#        TMP_PID=`ps -ax|grep -v grep | grep "${CONF_NAME} open"| cut -d" " -f2`
#        ${KILL} -9 $TMP_PID

}



####

case "$2" in
  open)
	${ECHO} "Open ..."

	F_KILL_PROCESS_CLOSE

	sleep 1

	# Wlaczenie Zasilanie 5v
	F_RELAY_POWER_ON

	# Start i2cset relay
	${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} ${CONF_RELAY11} ${CONF_OPEN11}
	${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} ${CONF_RELAY12} ${CONF_OPEN12}
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} ${CONF_RELAY21} ${CONF_STOP21}
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} ${CONF_RELAY22} ${CONF_STOP22}

#        echo "${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} ${CONF_RELAY11} ${CONF_OPEN11}"
#        echo "${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} ${CONF_RELAY12} ${CONF_OPEN12}"
#        echo "${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} ${CONF_RELAY21} ${CONF_STOP21}"
#        echo "${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} ${CONF_RELAY22} ${CONF_STOP22}"


	sleep $CONF_TIME

        # Wylaczenie na koniec
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} ${CONF_RELAY11} ${CONF_STOP1}
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} ${CONF_RELAY12} ${CONF_STOP1}
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} ${CONF_RELAY21} ${CONF_STOP1}
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} ${CONF_RELAY22} ${CONF_STOP1}

#        echo "${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} ${CONF_RELAY11} ${CONF_STOP1}"
#        echo "${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} ${CONF_RELAY12} ${CONF_STOP1}"
#        echo "${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} ${CONF_RELAY21} ${CONF_STOP1}"
#        echo "${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} ${CONF_RELAY22} ${CONF_STOP1}"


        # Power 5v sprawdza status i wylacza przekaznik
        F_RELAY_POWER_CHECK

        # Wylaczenie Zasilanie 5v
#        F_RELAY_POWER_OFF

	# Send status to the file
	${ECHO} 1 > ${GPIO_DIR}/${CONF_NAME}.state

	;;
  close)
        ${ECHO} "Close ..."

	F_KILL_PROCESS_OPEN

#	sleep 1

        # Wlaczenie Zasilanie 5v
        F_RELAY_POWER_ON

        # Close i2cset relay
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} ${CONF_RELAY11} ${CONF_OPEN11}
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} ${CONF_RELAY12} ${CONF_OPEN12}
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} ${CONF_RELAY21} ${CONF_OPEN21}
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} ${CONF_RELAY22} ${CONF_OPEN22}

        sleep $CONF_TIME

        # Wylaczenie na koniec
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} ${CONF_RELAY11} ${CONF_STOP1}
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} ${CONF_RELAY12} ${CONF_STOP1}
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} ${CONF_RELAY21} ${CONF_STOP1}
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} ${CONF_RELAY22} ${CONF_STOP1} 

        # Power 5v sprawdza status i wylacza przekaznik
        F_RELAY_POWER_CHECK

        # Wlaczenie Zasilanie 5v
#        F_RELAY_POWER_OFF

	# Send status to the file
	${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME}.state

	;;
  stop)
	${ECHO} "Stop ..."

#	sleep 1

        # Wlaczenie Zasilanie 5v
#        F_RELAY_POWER_OFF

	# Stop i2cset relay
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} ${CONF_RELAY11} ${CONF_STOP1}
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS1} ${CONF_RELAY12} ${CONF_STOP1}
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} ${CONF_RELAY21} ${CONF_STOP1}
        ${I2CSET} -y ${PCA9685_BUS} ${CONF_ADDRESS2} ${CONF_RELAY22} ${CONF_STOP1}

	# Kill process
	F_KILL_PROCESS_OPEN
	F_KILL_PROCESS_CLOSE

	# Send status to the file
	${ECHO} 2 > ${GPIO_DIR}/${CONF_NAME}.state

	# Power 5v sprawdza status i wylacza przekaznik
	F_RELAY_POWER_CHECK


	;;
  *)
    echo "Usage: {start|stop}"
    exit 1
    ;;
esac

exit 0

