#!/bin/sh
GPIO_DIR=/var/www/gpio
ARCHIVE_DIR=/var/www/_old
WWW_DIR=/var/www
ECHO=/bin/echo
KILL=/bin/kill


ARCHIVE_FILE_STATUS=archive.state

###
F_COPY () {
	cp ${WWW_DIR}/${FILE_NAME} ${ARCHIVE_DIR}/${FILE_NAME}_${STATE}
}

###

# Pobiera ostatni numer z pliku i dodaje 1 aby zwiekszyc licznik
STATE=`cat ${ARCHIVE_DIR}/${ARCHIVE_FILE_STATUS}`
STATE=$((STATE+1))


# Kopiowanie pliku
FILE_NAME=script.sh
F_COPY

FILE_NAME=script.js
F_COPY

FILE_NAME=index.php
F_COPY

FILE_NAME=script_full.js
F_COPY

FILE_NAME=index_full.php
F_COPY

FILE_NAME=script_duzy_poludnie.sh
F_COPY

FILE_NAME=script_duzy_wschod.sh
F_COPY

FILE_NAME=script_duzy_zachod.sh
F_COPY

FILE_NAME=script_duzy.sh
F_COPY

FILE_NAME=script_brama.sh
F_COPY

FILE_NAME=script_brama_garaz.sh
F_COPY

FILE_NAME=script_brama_wjazd.sh
F_COPY

FILE_NAME=script_one.sh
F_COPY

FILE_NAME=brama.php
F_COPY

FILE_NAME=script_brama.js
F_COPY


# Wysyla do pliku ostatni numer kolejnej wersji
${ECHO} ${STATE} > ${ARCHIVE_DIR}/${ARCHIVE_FILE_STATUS}

# List files in archive
ls -al ${ARCHIVE_DIR}/*${STATE}
