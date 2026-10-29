#!/bin/sh
TIME=2

# Wentylacja
/usr/bin/curl 'http://10.0.0.8/API/RunFunction?name=TurnOn'
/usr/bin/curl 'http://10.0.0.9/API/RunFunction?name=TurnOn'
sleep $TIME

#/usr/bin/curl 'http://10.0.0.8/API/RunFunction?name=AutomaticMode'
#/usr/bin/curl 'http://10.0.0.9/API/RunFunction?name=AutomaticMode'

/usr/bin/curl 'http://10.0.0.8/API/RunFunction?name=ManualMode&airflowvalue=80&valueofairextraction=70'
/usr/bin/curl 'http://10.0.0.9/API/RunFunction?name=ManualMode&airflowvalue=80&valueofairextraction=70'

exit 0
