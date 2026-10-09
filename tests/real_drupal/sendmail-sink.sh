#!/bin/sh
set -eu
: "${SENDREPUTE_MAIL_SINK:?}"
{
  printf '%s\n' "--- sendmail $* ---"
  cat
} >> "$SENDREPUTE_MAIL_SINK"