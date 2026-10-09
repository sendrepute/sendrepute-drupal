#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)
unset SENDREPUTE_API_TOKEN SENDREPUTE_API_BASE
VERSIONS=${DRUPAL_REAL_VERSIONS:-"10.6.17 11.4.7"}
REQUESTED_WORK=${DRUPAL_REAL_WORK_ROOT:-}
WEB_PORT=${DRUPAL_REAL_WEB_PORT:-}
if [ -n "$REQUESTED_WORK" ] && { [ -e "$REQUESTED_WORK" ] || [ -L "$REQUESTED_WORK" ]; }; then
  echo "Refusing existing DRUPAL_REAL_WORK_ROOT: $REQUESTED_WORK" >&2
  exit 2
fi
if [ -n "$WEB_PORT" ]; then
  offset=0
  for unused_version in $VERSIONS; do
    candidate=$((WEB_PORT + offset))
    php -r '$s=@stream_socket_server("tcp://127.0.0.1:".$argv[1],$e,$m); if (!$s) {fwrite(STDERR,"Refusing occupied HTTP port ".$argv[1]."\n"); exit(2);} fclose($s);' "$candidate"
    offset=$((offset + 1))
  done
fi
if [ -n "$REQUESTED_WORK" ]; then
  mkdir -m 700 -- "$REQUESTED_WORK"
  WORK=$REQUESTED_WORK
else
  WORK=$(mktemp -d "${TMPDIR:-/tmp}/sendrepute-drupal-real.XXXXXX")
fi
DB="$WORK/mariadb"
SOCKET="$WORK/mariadb.sock"
PID="$WORK/mariadb.pid"
EVIDENCE="${DRUPAL_REAL_EVIDENCE_FILE:-$ROOT/tests/real_drupal/evidence.json}"
mkdir -p "$(dirname "$EVIDENCE")"

cleanup() {
  [ -z "${WEB_PID:-}" ] || kill "$WEB_PID" 2>/dev/null || true
  [ -z "${DB_PID:-}" ] || kill "$DB_PID" 2>/dev/null || true
  [ -z "${DB_PID:-}" ] || wait "$DB_PID" 2>/dev/null || true
  if [ -n "${WORK:-}" ] && [ -d "$WORK" ]; then
    find "$WORK" -type d -name default -path '*/sites/default' -exec chmod u+w {} \;
    find "$WORK" -name settings.php -o -name default.settings.php -o -name default.services.yml |
      while IFS= read -r file; do chmod u+w "$file" 2>/dev/null || true; done
    rm -rf -- "$WORK"
  fi
}
trap cleanup EXIT INT TERM
mariadb-install-db --no-defaults --datadir="$DB" --auth-root-authentication-method=normal >/dev/null
mariadbd --no-defaults --datadir="$DB" --socket="$SOCKET" --pid-file="$PID" \
  --skip-networking --skip-name-resolve --log-error="$WORK/mariadb.log" &
DB_PID=$!
for unused in 1 2 3 4 5 6 7 8 9 10; do
  kill -0 "$DB_PID" 2>/dev/null || {
    echo "Owned MariaDB process exited before readiness." >&2
    exit 1
  }
  mariadb-admin --protocol=socket --socket="$SOCKET" -uroot ping >/dev/null 2>&1 && break
  sleep 1
done
kill -0 "$DB_PID"
mariadb-admin --protocol=socket --socket="$SOCKET" -uroot ping >/dev/null

python3 "$ROOT/package.py"
PACKAGE_SHA=$(sha256sum "$ROOT/dist/sendrepute-drupal-0.1.0.zip" | cut -d' ' -f1)
printf '{"php":"%s","composer":"%s","mariadb":"%s","package_sha256":"%s","drupal":[' \
  "$(php -r 'echo PHP_VERSION;')" "$(composer --version --no-ansi | sed 's/"/\\"/g')" \
  "$(mariadb --version | sed 's/"/\\"/g')" "$PACKAGE_SHA" > "$EVIDENCE"
separator=
index=0
for version in $VERSIONS; do
  project="$WORK/drupal-$version"
  database="sendrepute_drupal_$index"
  composer create-project "drupal/recommended-project:$version" "$project" --no-interaction --no-progress
  kill -0 "$DB_PID"
  mariadb --protocol=socket --socket="$SOCKET" -uroot -e "CREATE DATABASE \`$database\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
  mkdir -p "$project/web/modules/custom"
  php -r '$z=new ZipArchive();$z->open($argv[1]);$z->extractTo($argv[2]);$z->close();' \
    "$ROOT/dist/sendrepute-drupal-0.1.0.zip" "$project/web/modules/custom"
  cp -R "$ROOT/tests/real_drupal/fixture_module" "$project/web/modules/custom/sendrepute_harness"
  php -d disable_functions=mail -d "pdo_mysql.default_socket=$SOCKET" -d "mysqli.default_socket=$SOCKET" \
    "$ROOT/tests/real_drupal/install.php" "$project" "$database" "$SOCKET"
  php "$ROOT/tests/real_drupal/enable.php" "$project"

  if [ -n "$WEB_PORT" ]; then
    port=$((WEB_PORT + index))
  else
    port=$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m);$n=stream_socket_get_name($s,FALSE);echo substr(strrchr($n,":"),1);fclose($s);')
  fi
  (cd "$project/web" && php -d disable_functions=mail -S "127.0.0.1:$port" .ht.router.php) >"$WORK/web-$version.log" 2>&1 &
  WEB_PID=$!
  sleep 2
  if ! kill -0 "$WEB_PID" 2>/dev/null; then
    echo "Owned HTTP server failed to bind port $port; refusing to contact another process." >&2
    exit 1
  fi
  python3 "$ROOT/tests/real_drupal/http_admin.py" "http://127.0.0.1:$port"
  kill "$WEB_PID"
  wait "$WEB_PID" 2>/dev/null || true
  WEB_PID=

  # Exercise an archive update while enabled: replace only with the newly
  # rebuilt allowlisted ZIP and rebuild Drupal's container/cache.
  php -r '$z=new ZipArchive();$z->open($argv[1]);$z->extractTo($argv[2]);$z->close();' \
    "$ROOT/dist/sendrepute-drupal-0.1.0.zip" "$project/web/modules/custom"
  sink="$WORK/mail-$version.eml"
  SENDREPUTE_MAIL_SINK="$sink" php -d "sendmail_path=sh $ROOT/tests/real_drupal/sendmail-sink.sh" \
    "$ROOT/tests/real_drupal/verify.php" "$project" "$version" "$sink"
  sha=$(sha256sum "$project/composer.lock" | cut -d' ' -f1)
  printf '%s{"version":"%s","composer_lock_sha256":"%s","real_mail_manager":"passed","anonymous_admin_denied":true,"restricted_authenticated_get_post_denied":true,"cross_session_admin_token_did_not_bypass_permission":true,"admin_csrf_rejected":true,"malicious_admin_input_escaped":true,"selected_high_risk_native_mail_cancelled":true,"critical_mail_preserved":true,"consent_off_skipped_classification":true,"synthetic_409_429_single_attempt":true,"classifier":"in-process synthetic client","not_verified":["server-side billing replay/concurrency; adapter is single-attempt and stateless"]}' "$separator" "$version" "$sha" >> "$EVIDENCE"
  separator=,
  index=$((index + 1))
done
printf ']}\n' >> "$EVIDENCE"
echo "Real Drupal evidence: $EVIDENCE"