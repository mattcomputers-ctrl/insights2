#!/bin/sh
# Entry point for the integration-test app container (see run-local.sh).
# Copies the repo (mounted read-only at /src) to /app, installs the test
# config, waits for MariaDB, migrates, then serves and crawls.
set -e

echo "[entry] copying app to /app…"
mkdir -p /app
cd /src
# tar pipe: everything except VCS/vendor noise; vendor comes from /src if present
tar cf - --exclude=.git --exclude=storage/logs --exclude=storage/cache \
    --exclude=storage/exports . | (cd /app && tar xf -)
mkdir -p /app/storage/logs /app/storage/cache /app/storage/exports

cp /app/tests/integration/config.test.php /app/config/config.php

echo "[entry] waiting for MariaDB…"
tries=0
until php -r '
    $c = require "/app/config/config.php";
    try {
        new PDO("mysql:host={$c["db"]["host"]};dbname={$c["db"]["name"]}", $c["db"]["user"], $c["db"]["password"]);
        exit(0);
    } catch (Throwable $e) { exit(1); }
'; do
    tries=$((tries+1))
    [ "$tries" -gt 60 ] && { echo "[entry] MariaDB never became ready"; exit 1; }
    sleep 1
done

echo "[entry] running migrations…"
cd /app
php migrations/migrate.php

echo "[entry] starting php -S…"
php -S 0.0.0.0:8080 -t public tests/integration/router.php >/tmp/server.log 2>&1 &
SERVER_PID=$!
sleep 1

echo "[entry] running unit tests…"
php tests/run.php

echo "[entry] running permission crawl…"
set +e
php tests/integration/crawl.php http://127.0.0.1:8080
CRAWL=$?
if [ "$CRAWL" -eq 0 ]; then
    echo "[entry] running sync-flow test…"
    php tests/integration/sync-flow.php http://127.0.0.1:8080
    CRAWL=$?
fi
if [ "$CRAWL" -eq 0 ]; then
    echo "[entry] running order-readiness flow test…"
    php tests/integration/or-flow.php http://127.0.0.1:8080
    CRAWL=$?
fi
if [ "$CRAWL" -eq 0 ]; then
    echo "[entry] running inventory-browser flow test…"
    php tests/integration/inv-flow.php http://127.0.0.1:8080
    CRAWL=$?
fi
set -e

if [ "$CRAWL" -ne 0 ]; then
    echo "[entry] ---- last server log lines ----"
    tail -40 /tmp/server.log || true
fi

kill $SERVER_PID 2>/dev/null || true
exit $CRAWL
