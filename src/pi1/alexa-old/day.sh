#!/bin/sh
TIME=10

# Caly dol
#/usr/bin/curl 'http://10.0.0.5/index.php?sw4=on'
#sleep $TIME

# Duzy pokoj
#/usr/bin/curl 'http://10.0.0.4/index.php?sw2=on'
#sleep $TIME

# Duzy zachod
/usr/bin/curl 'http://10.0.0.4/index.php?sw5=on'
sleep $TIME

# Pietro 2
#sleep $TIME
#/usr/bin/curl 'http://10.0.0.5/index.php?sw5=on'

# Mama
/usr/bin/curl 'http://10.0.0.5/index.php?sw1=on'
sleep $TIME

# Przemek
/usr/bin/curl 'http://10.0.0.5/index.php?sw2=on'
sleep $TIME

# Olek
/usr/bin/curl 'http://10.0.0.5/index.php?sw3=on'
sleep $TIME

# Dach
#/usr/bin/curl 'http://10.0.0.4/index.php?sw13=on'
#sleep $TIME

exit 0
