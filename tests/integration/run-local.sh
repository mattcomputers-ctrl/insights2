#!/bin/sh
# Run the full integration suite (unit tests + permission crawl) locally
# in Docker: a throwaway MariaDB + a php:8.3 container serving the app.
#
#   sh tests/integration/run-local.sh
#
# Needs Docker. Creates/uses image pii-test-php and network pii-test.
# The repo is mounted read-only; nothing in it is modified (vendor/ must
# already exist — run composer install first if it doesn't).
set -e
cd "$(dirname "$0")/../.."
REPO="$(pwd)"

if [ ! -d vendor ]; then
    echo "vendor/ missing — installing composer dependencies (one-off)…"
    MSYS_NO_PATHCONV=1 docker run --rm -v "$REPO:/app" -w /app composer:2 \
        composer install --no-dev --ignore-platform-reqs --no-interaction --quiet
fi

echo "Building test PHP image (cached after first run)…"
MSYS_NO_PATHCONV=1 docker build -q -t pii-test-php tests/integration >/dev/null

docker network inspect pii-test >/dev/null 2>&1 || docker network create pii-test >/dev/null

echo "Starting MariaDB…"
docker rm -f pii-test-db >/dev/null 2>&1 || true
docker run -d --rm --name pii-test-db --network pii-test \
    -e MARIADB_DATABASE=insights_test \
    -e MARIADB_USER=insights \
    -e MARIADB_PASSWORD=insights_test_pw \
    -e MARIADB_ROOT_PASSWORD=root_test_pw \
    mariadb:11 >/dev/null

STATUS=0
MSYS_NO_PATHCONV=1 docker run --rm --name pii-test-app --network pii-test \
    -v "$REPO:/src:ro" pii-test-php sh /src/tests/integration/entry.sh || STATUS=$?

docker rm -f pii-test-db >/dev/null 2>&1 || true
exit $STATUS
