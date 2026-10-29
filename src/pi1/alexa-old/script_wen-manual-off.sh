#!/bin/sh
TIME=10

# Wentylacja
/usr/bin/curl 'http://10.0.0.8/API/RunFunction?name=AutomaticMode'
/usr/bin/curl 'http://10.0.0.9/API/RunFunction?name=AutomaticMode'

exit 0
