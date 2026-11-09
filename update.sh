#!/bin/bash
# ============================================================
# Precision Ink Insights — update shim.
# The real logic now lives in install.sh (re-running it IS the update:
# backup → pull → composer → migrations → tests with rollback →
# maintenance → service restarts). This wrapper keeps the old command
# working:   sudo bash /var/www/precision-ink-insights/update.sh
# ============================================================
set -u
INSTALL_DIR="/var/www/precision-ink-insights"

# Self-relocate first (the pull below replaces this very file).
if [ -z "${INSIGHTS_RELOCATED_SHIM:-}" ] && [[ "$0" == "$INSTALL_DIR"* ]]; then
    TMP_SELF=$(mktemp /tmp/insights-update.XXXXXX.sh)
    cp "$0" "$TMP_SELF"
    export INSIGHTS_RELOCATED_SHIM=1
    exec bash "$TMP_SELF" "$@"
fi

exec bash "$INSTALL_DIR/install.sh" "$@"
