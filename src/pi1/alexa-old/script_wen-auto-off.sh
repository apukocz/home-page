#!/bin/sh
TIME=10

# Wentylacja
/usr/bin/curl 'http://10.0.0.8/API/RunFunction?name=ManualMode&airflowvalue=150&valueofairextraction=150'
/usr/bin/curl 'http://10.0.0.9/API/RunFunction?name=ManualMode&airflowvalue=150&valueofairextraction=150'

exit 0
