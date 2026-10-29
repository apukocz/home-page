#!/bin/sh
GPIO=1
URL="http://10.0.0.4/index.php?sw1=on"

/usr/bin/curl $URL
rm gpio_${GPIO}.txt
