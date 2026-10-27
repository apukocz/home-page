#!/bin/sh

GPIO_DIR=/var/www/gpio
ECHO=/bin/echo


TMP=`ps -ax|grep -v grep | grep /home/cubie/.scripts/serial.sh | cut -d" " -f2`

if [ ! ${TMP} ]; then

sh /home/cubie/.scripts/serial.sh &

fi 

exit 0
