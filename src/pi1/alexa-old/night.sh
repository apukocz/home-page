#!/bin/sh
TIME=10

# Caly dol
#/usr/bin/curl 'http://10.0.0.5/index.php?sw4=off'
#sleep $TIME

# Duzy pokoj
#/usr/bin/curl 'http://10.0.0.4/index.php?sw2=off'
#sleep $TIME

# Duzy zachod - lato
/usr/bin/curl 'http://10.0.0.4/index.php?sw5=off'
sleep $TIME

# Pietro 2
#sleep $TIME
#/usr/bin/curl 'http://10.0.0.5/index.php?sw5=off'

# Mama
/usr/bin/curl 'http://10.0.0.5/index.php?sw1=off'
sleep $TIME

# Przemek
/usr/bin/curl 'http://10.0.0.5/index.php?sw2=off'
sleep $TIME

# Olek
/usr/bin/curl 'http://10.0.0.5/index.php?sw3=off'
sleep $TIME

# Dach
#/usr/bin/curl 'http://10.0.0.4/index.php?sw13=off'
#sleep $TIME

exit 0
