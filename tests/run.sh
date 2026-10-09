#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
php tests/fixtures.php
php tests/console-fixtures.php
php ../wordpress/portable-console/tests/core-fixtures.php sendrepute/src/Console "Drupal\\sendrepute\\Console"
python3 tests/api_contract_test.py
python3 tests/package_test.py
if [ -n "${DRUPAL_CORE_PATH:-}" ]; then
  php tests/drupal_core_harness.php
else
  echo "Drupal core source harness requires DRUPAL_CORE_PATH (CI passes official immutable source)."
fi
if [ "${DRUPAL_REAL:-0}" = 1 ]; then
  sh tests/run-installed.sh
else
  echo "Real installed Drupal harness requires DRUPAL_REAL=1 (isolated Composer/MariaDB run)."
fi
