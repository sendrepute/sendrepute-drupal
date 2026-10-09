#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
exec sh tests/real_drupal/run.sh