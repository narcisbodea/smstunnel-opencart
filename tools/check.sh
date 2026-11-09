#!/usr/bin/env bash
# php -l on every PHP file and the unit tests, on each PHP version the extension meets:
#   7.1 (the oldest the core and the OpenCart 3.0 files support), 7.4 (many OpenCart 3.0.3 stores),
#   8.1 (OpenCart 3.0.5 and 4.1 minimum), 8.2 (OpenCart 4.0.2), 8.3, 8.4 and 8.5.
# The OpenCart 4 files use PHP 8 syntax and are checked on PHP 8 only. First, on PHP 5.6: php -l of the files
# OpenCart 3.0 loads before the extension checks the PHP version, and tests/oldphp/run.php (the OpenCart 3.0
# controllers with stand-ins for OpenCart: on an old PHP they do nothing and load none of the extension's classes).
# The unit tests' API part runs against the fake SMSTunnel API (tests/fake-api/server.mjs), started here
# on a free port of 127.0.0.1 and stopped at the end. Nothing goes to smstunnel.io.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if command -v cygpath >/dev/null 2>&1; then
  ROOT="$(cygpath -m "$ROOT")"
fi
IMAGES=("php:7.1-cli-alpine" "php:7.4-cli" "php:8.1-cli-alpine" "php:8.2-cli" "php:8.3-cli" "php:8.4-cli-alpine" "php:8.5-cli-alpine")

PORT_FILE="$ROOT/tests/fake-api/check-port.txt"
rm -f "$PORT_FILE"
node "$ROOT/tests/fake-api/server.mjs" --port 0 --port-file "$PORT_FILE" > "$ROOT/tests/fake-api/check.log" 2>&1 &
for _ in $(seq 1 50); do [ -s "$PORT_FILE" ] && break; sleep 0.1; done
PORT="$(cat "$PORT_FILE")"
stop_fake() { curl -s -X POST "http://127.0.0.1:$PORT/__shutdown" >/dev/null 2>&1 || true; rm -f "$PORT_FILE"; }
trap stop_fake EXIT
echo "fake SMSTunnel API on 127.0.0.1:$PORT"

status=0
# OpenCart 3.0 also runs on PHP 5.x: the files it loads before the extension checks the PHP version must
# parse there, so that an old PHP gets "needs PHP 7.1" and an untouched checkout instead of a fatal error.
echo "== php:5.6-cli-alpine (OpenCart 3.0 entry points only)"
MSYS_NO_PATHCONV=1 docker run --rm -v "$ROOT:/repo:ro" -w /repo --entrypoint sh php:5.6-cli-alpine -c '
  bad=0
  for f in src/oc3/upload/admin/controller/extension/module/smstunnel.php src/oc3/upload/catalog/controller/extension/module/smstunnel.php \
           src/core/smstunnel/autoload.php src/core/smstunnel/Guard.php src/language/en-gb.php src/language/ro-ro.php            tests/oldphp/run.php; do
    out=$(php -l "$f" 2>&1) || { echo "$out"; bad=1; }
  done
  [ $bad -eq 0 ] && echo "php -l: OpenCart 3.0 entry points OK on PHP $(php -r "echo PHP_VERSION;")"
  for side in admin catalog; do
    out=$(php tests/oldphp/run.php $side 2>&1) || { echo "$out"; bad=1; continue; }
    echo "$out" | tail -1
  done
  exit $bad' || status=1

for image in "${IMAGES[@]}"; do
  echo "== $image"
  MSYS_NO_PATHCONV=1 docker run --rm -v "$ROOT:/repo:ro" -w /repo \
    -e SMSTUNNEL_FAKE_URL="http://host.docker.internal:$PORT" \
    --add-host=host.docker.internal:host-gateway \
    --entrypoint sh "$image" -c '
      php -r "echo PHP_VERSION, PHP_EOL;"
      bad=0
      major=$(php -r "echo PHP_MAJOR_VERSION;")
      files=$(find src/core src/language src/oc3 tests/unit tests/oldphp tools -name "*.php")
      if [ "$major" -ge 8 ]; then files="$files $(find src/oc4 -name "*.php")"; fi
      for f in $files; do
        out=$(php -l "$f" 2>&1) || { echo "$out"; bad=1; }
      done
      [ $bad -eq 0 ] && echo "php -l: all files OK"
      php tests/unit/run.php || bad=1
      exit $bad' || status=1
done
exit $status
