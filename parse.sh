#!/bin/bash
if [ "$1" == "-h" ] || [ "$1" == "--help" ] ; then
  cat << EOF
Usage: parse.sh [/path/to/fio-test.json]

Parses fio results files fio-[test].json and renders result metrics as 
key/value pairs. For example: name1=x1-0_100-4k-rand-ssmw. Keys use an 
incrementing numeric value for each job (e.g. name1=x1-0_100-4k-rand-ssmw, 
name2=x2-0_100-4k-rand-ssmw).


EXIT CODES:
  0 results parsing successful
  1 results parsing failed

EOF
  exit
elif [ -f "/usr/bin/php" ]; then
  $( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )/lib/parse.php $@
  exit $?
else
  echo "Error: missing dependency php-cli (/usr/bin/php)"
  exit 1
fi
