#!/bin/bash
# ============================================================
# Precision Ink Insights — installer / updater (SPEC §5, adapted:
# Apache + mod_php kept; pdo_sqlsrv attempted with dblib fallback).
#
# Fresh Ubuntu Server (22.04/24.04 LTS):
#   curl -fsSL https://raw.githubusercontent.com/mattcomputers-ctrl/precisionink-insights/main/install.sh | sudo bash
#
# Update (re-run = update):
#   sudo insights-update          (wrapper installed by this script)
#   sudo bash /var/www/precision-ink-insights/install.sh
#
# Flags: --check  --force  --branch <name>  --unattended  --skip-network
# Env overrides (unattended installs): INSIGHTS_ADMIN_USER, INSIGHTS_ADMIN_PASS,
#   CMS_DB_HOST, CMS_DB_USER, CMS_DB_PASS, CMS_DB_NAME, INSIGHTS_BRANCH,
#   INSIGHTS_SKIP_SQLSRV=1, SERVER_NAME, COMPANY_NAME, TIMEZONE
# ============================================================
set -u

INSTALL_DIR="/var/www/precision-ink-insights"
REPO_URL="${INSIGHTS_REPO_URL:-https://github.com/mattcomputers-ctrl/precisionink-insights.git}"
BRANCH="${INSIGHTS_BRANCH:-main}"
BACKUP_DIR="/var/backups/insights"
HELPER="/usr/local/sbin/insights-helper"

CHECK_ONLY=0; FORCE=0; UNATTENDED=0; SKIP_NETWORK=0
while [ $# -gt 0 ]; do
    case "$1" in
        --check) CHECK_ONLY=1 ;;
        --force) FORCE=1 ;;
        --branch) BRANCH="$2"; shift ;;
        --unattended) UNATTENDED=1 ;;
        --skip-network) SKIP_NETWORK=1 ;;
        *) echo "Unknown flag: $1"; exit 2 ;;
    esac
    shift
done

# Self-relocate: a git reset below swaps this file's inode mid-run; bash
# keeps reading the old blocks, so the rest of the run would be stale.
if [ -z "${INSIGHTS_RELOCATED:-}" ] && [ -f "$0" ] && [[ "$0" == "$INSTALL_DIR"* ]]; then
    TMP_SELF=$(mktemp /tmp/insights-install.XXXXXX.sh)
    cp "$0" "$TMP_SELF"
    export INSIGHTS_RELOCATED=1
    exec bash "$TMP_SELF" \
        $([ $CHECK_ONLY -eq 1 ] && echo --check) $([ $FORCE -eq 1 ] && echo --force) \
        $([ $UNATTENDED -eq 1 ] && echo --unattended) $([ $SKIP_NETWORK -eq 1 ] && echo --skip-network) \
        --branch "$BRANCH"
fi

say()  { echo -e "\033[1;34m==>\033[0m $*"; }
okay() { echo -e "  \033[1;32m[ok]\033[0m $*"; }
warn() { echo -e "  \033[1;33m[!!]\033[0m $*"; }
fail() { echo -e "  \033[1;31m[XX]\033[0m $*"; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "Run as root: sudo bash install.sh"

HAS_SYSTEMD=1
[ -d /run/systemd/system ] || { HAS_SYSTEMD=0; warn "systemd not running (container?). Services will be installed but not started."; }

sctl() { [ $HAS_SYSTEMD -eq 1 ] && systemctl "$@" || true; }
# Start/restart a service even without systemd (containers, test VMs).
svc() {  # svc <restart|start|reload> <name>
    if [ $HAS_SYSTEMD -eq 1 ]; then systemctl "$1" "$2" 2>/dev/null || true
    else service "$2" "$1" >/dev/null 2>&1 || service "$2" start >/dev/null 2>&1 || true; fi
}

# ── OS check ──────────────────────────────────────────────────
. /etc/os-release 2>/dev/null || fail "Cannot read /etc/os-release — is this Ubuntu?"
case "${ID:-}-${VERSION_ID:-}" in
    ubuntu-22.04|ubuntu-24.04|ubuntu-24.10|ubuntu-26.04) okay "Ubuntu $VERSION_ID" ;;
    *) fail "This installer supports Ubuntu Server 22.04/24.04 LTS (found: ${ID:-?} ${VERSION_ID:-?})." ;;
esac

IS_UPDATE=0
[ -d "$INSTALL_DIR/.git" ] && IS_UPDATE=1

# ============================================================
#  UPDATE PATH  (re-run on an existing install)
# ============================================================
if [ $IS_UPDATE -eq 1 ]; then
    say "Existing install detected at $INSTALL_DIR — running the update."

    sudo -u www-data git -C "$INSTALL_DIR" fetch --quiet origin "$BRANCH" \
        || fail "git fetch failed — check network access to GitHub."
    OLD=$(sudo -u www-data git -C "$INSTALL_DIR" rev-parse HEAD)
    NEW=$(sudo -u www-data git -C "$INSTALL_DIR" rev-parse "origin/$BRANCH")

    if [ $CHECK_ONLY -eq 1 ]; then
        if [ "$OLD" = "$NEW" ]; then echo "Up to date ($OLD)."; else echo "Update available: $OLD -> $NEW"; fi
        exit 0
    fi

    if [ "$OLD" = "$NEW" ] && [ $FORCE -eq 0 ]; then
        say "Already at the latest version ($(echo "$OLD" | cut -c1-9)). Running maintenance only."
        CHANGED=""
    else
        # Back up BEFORE touching anything (spec §5).
        say "Backing up code and database first…"
        mkdir -p "$BACKUP_DIR"
        STAMP=$(date +%Y%m%d-%H%M%S)
        tar -C "$(dirname "$INSTALL_DIR")" -czf "$BACKUP_DIR/code-pre-update-$STAMP.tgz" \
            --exclude='*/storage/logs/*' --exclude='*/vendor/*' "$(basename "$INSTALL_DIR")" \
            && okay "Code backup: $BACKUP_DIR/code-pre-update-$STAMP.tgz"
        DBN=$(php -r '$c=require "'"$INSTALL_DIR"'/config/config.php"; echo $c["db"]["name"];' 2>/dev/null)
        if [ -n "$DBN" ]; then
            php -r '$c=require "'"$INSTALL_DIR"'/config/config.php";
                file_put_contents("/tmp/insights-my.cnf", "[client]\nuser={$c["db"]["user"]}\npassword={$c["db"]["password"]}\nhost={$c["db"]["host"]}\n");
                chmod("/tmp/insights-my.cnf", 0600);'
            if mysqldump --defaults-extra-file=/tmp/insights-my.cnf --single-transaction "$DBN" 2>/dev/null \
                | gzip > "$BACKUP_DIR/db-pre-update-$STAMP.sql.gz"; then
                okay "DB backup: $BACKUP_DIR/db-pre-update-$STAMP.sql.gz"
            else
                warn "Database backup failed — continuing (code backup exists)."
            fi
            rm -f /tmp/insights-my.cnf
        fi

        say "Updating code $(echo "$OLD" | cut -c1-9) -> $(echo "$NEW" | cut -c1-9)…"
        # reset --hard so chmod-induced mode drift never blocks the pull
        # (long-established pattern on this server).
        sudo -u www-data git -C "$INSTALL_DIR" reset --hard "origin/$BRANCH" --quiet
        CHANGED=$(sudo -u www-data git -C "$INSTALL_DIR" diff --name-only "$OLD" "$NEW")
    fi

    if echo "$CHANGED" | grep -qE '^composer\.(json|lock)$'; then
        say "composer.json changed — composer install…"
        sudo -u www-data composer install -d "$INSTALL_DIR" --no-dev -o --quiet || fail "composer install failed."
    fi

    say "Running migrations…"
    sudo -u www-data php "$INSTALL_DIR/migrations/migrate.php" || {
        warn "Migrations FAILED — rolling code back to $OLD."
        sudo -u www-data git -C "$INSTALL_DIR" reset --hard "$OLD" --quiet
        fail "Update rolled back. The database was NOT rolled back — investigate before retrying."
    }

    say "Running the test suite (includes the Margin Watchdog regression fixtures)…"
    if ! sudo -u www-data php "$INSTALL_DIR/tests/run.php" > /tmp/insights-tests.log 2>&1; then
        warn "TESTS FAILED — rolling back to $OLD. Log: /tmp/insights-tests.log"
        tail -20 /tmp/insights-tests.log || true
        sudo -u www-data git -C "$INSTALL_DIR" reset --hard "$OLD" --quiet
        sudo -u www-data composer install -d "$INSTALL_DIR" --no-dev -o --quiet || true
        sctl restart insights-sync-worker 2>/dev/null
        sctl reload apache2 2>/dev/null
        fail "Update rolled back; services restored on the previous version."
    fi
    okay "Tests green: $(tail -1 /tmp/insights-tests.log)"
fi

# ============================================================
#  FRESH-INSTALL-ONLY work (prompts, packages, config, database)
# ============================================================
export DEBIAN_FRONTEND=noninteractive

if [ $IS_UPDATE -eq 0 ]; then
    say "Fresh install."
    SERVER_NAME="${SERVER_NAME:-insights.local}"
    COMPANY_NAME="${COMPANY_NAME:-Precision Ink Insights}"
    TIMEZONE="${TIMEZONE:-America/Chicago}"
    ADMIN_USER="${INSIGHTS_ADMIN_USER:-}"
    ADMIN_PASS="${INSIGHTS_ADMIN_PASS:-}"

    if [ $UNATTENDED -eq 0 ] && [ -t 0 ]; then
        read -rp "Admin username [admin]: " _v; ADMIN_USER="${_v:-${ADMIN_USER:-admin}}"
        while :; do
            read -rsp "Admin password (min 10 chars): " P1; echo
            read -rsp "Admin password again: " P2; echo
            [ "$P1" = "$P2" ] || { echo "Passwords don't match."; continue; }
            [ ${#P1} -ge 10 ] || { echo "Use at least 10 characters."; continue; }
            ADMIN_PASS="$P1"; break
        done
        read -rp "CMS SQL Server host [10.10.10.11] (empty = set later in Admin): " _v; CMS_DB_HOST="${_v:-${CMS_DB_HOST:-}}"
        if [ -n "${CMS_DB_HOST:-}" ]; then
            read -rp  "CMS database [CMS]: " _v; CMS_DB_NAME="${_v:-CMS}"
            read -rp  "CMS read-only username: " CMS_DB_USER
            read -rsp "CMS password: " CMS_DB_PASS; echo
        fi
    else
        ADMIN_USER="${ADMIN_USER:-admin}"
        [ -n "$ADMIN_PASS" ] || { ADMIN_PASS=$(openssl rand -base64 15); GENERATED_PASS=1; }
    fi
    [ ${#ADMIN_PASS} -ge 10 ] || fail "Admin password must be at least 10 characters (set INSIGHTS_ADMIN_PASS)."

    say "Installing packages (Apache, MariaDB, PHP)…"
    apt-get update -qq
    apt-get install -y -qq apache2 mariadb-server mariadb-client unzip curl git openssl composer cron logrotate \
        php php-cli php-mysql php-mbstring php-xml php-curl php-zip php-intl php-bcmath php-gd >/dev/null \
        || fail "Package install failed."
    a2enmod rewrite headers >/dev/null 2>&1 || true
    sctl enable mariadb apache2 >/dev/null 2>&1
    svc start mariadb; svc start apache2
    # Containers/test VMs without systemd still need a running DB.
    for i in 1 2 3 4 5 6 7 8 9 10; do mariadb -e "SELECT 1" >/dev/null 2>&1 && break; sleep 1; done
    mariadb -e "SELECT 1" >/dev/null 2>&1 || fail "MariaDB is not running."
    okay "Base packages installed."
fi

# ── SQL Server driver: try pdo_sqlsrv (ODBC 18), fall back to dblib ──
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
if php -m 2>/dev/null | grep -qi 'pdo_sqlsrv'; then
    okay "pdo_sqlsrv already installed."
elif [ "${INSIGHTS_SKIP_SQLSRV:-0}" = "1" ]; then
    warn "Skipping pdo_sqlsrv (INSIGHTS_SKIP_SQLSRV=1)."
else
    say "Attempting Microsoft ODBC 18 + pdo_sqlsrv (preferred CMS driver)…"
    SQLSRV_OK=0
    if curl -fsSL https://packages.microsoft.com/keys/microsoft.asc 2>/dev/null \
            | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg --yes 2>/dev/null; then
        echo "deb [arch=amd64,arm64 signed-by=/usr/share/keyrings/microsoft-prod.gpg] https://packages.microsoft.com/ubuntu/${VERSION_ID}/prod ${UBUNTU_CODENAME:-noble} main" \
            > /etc/apt/sources.list.d/mssql-release.list
        apt-get update -qq 2>/dev/null
        if ACCEPT_EULA=Y apt-get install -y -qq msodbcsql18 unixodbc-dev php-dev php-pear build-essential >/dev/null 2>&1; then
            if printf "\n" | pecl install -f sqlsrv pdo_sqlsrv >/dev/null 2>&1; then
                for sapi in cli apache2 fpm; do
                    d="/etc/php/$PHP_VER/$sapi/conf.d"
                    [ -d "$d" ] && { echo "extension=sqlsrv.so" > "$d/20-sqlsrv.ini"; echo "extension=pdo_sqlsrv.so" > "$d/30-pdo_sqlsrv.ini"; }
                done
                php -m | grep -qi pdo_sqlsrv && SQLSRV_OK=1
            fi
        fi
    fi
    if [ $SQLSRV_OK -eq 1 ]; then
        okay "pdo_sqlsrv installed."
    else
        warn "pdo_sqlsrv install didn't complete — falling back to the FreeTDS (dblib) driver."
        warn "The app fully supports dblib; retry sqlsrv later by re-running this installer."
    fi
fi
if ! php -m 2>/dev/null | grep -qiE 'pdo_sqlsrv|pdo_dblib'; then
    say "Installing FreeTDS (pdo_dblib) fallback…"
    apt-get install -y -qq "php${PHP_VER}-sybase" >/dev/null 2>&1 || apt-get install -y -qq php-sybase >/dev/null 2>&1 \
        || fail "No SQL Server driver could be installed (neither pdo_sqlsrv nor pdo_dblib)."
fi
okay "CMS driver present: $(php -m | grep -iE 'pdo_sqlsrv|pdo_dblib' | tr '\n' ' ')"

if [ $IS_UPDATE -eq 0 ]; then
    say "Fetching the application…"
    mkdir -p "$INSTALL_DIR"
    git clone --quiet --branch "$BRANCH" "$REPO_URL" "$INSTALL_DIR" || fail "git clone failed."
    chown -R www-data:www-data "$INSTALL_DIR"

    say "Creating the database…"
    DB_NAME="precision_ink_insights"
    DB_USER="pii_user"
    DB_PASS=$(openssl rand -base64 18 | tr -d '/+=')
    mariadb <<SQL || fail "Database creation failed."
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    okay "Database $DB_NAME ready."

    say "Writing configuration…"
    cat > "$INSTALL_DIR/config/config.php" <<CONFIGEOF
<?php
/** Precision Ink Insights — generated by install.sh on $(date '+%Y-%m-%d %H:%M:%S') */
return [
    'app' => [
        'name'     => '$COMPANY_NAME',
        'url'      => 'http://$SERVER_NAME',
        'debug'    => false,
        'timezone' => '$TIMEZONE',
        'version'  => '2.0.0',
    ],
    'db' => [
        'host' => '127.0.0.1', 'port' => 3306,
        'name' => '$DB_NAME', 'user' => '$DB_USER', 'password' => '$DB_PASS',
        'charset' => 'utf8mb4',
    ],
    'cms_db' => [
        'host' => '${CMS_DB_HOST:-}', 'port' => ${CMS_DB_PORT:-1433},
        'name' => '${CMS_DB_NAME:-CMS}', 'user' => '${CMS_DB_USER:-}',
        'password' => '${CMS_DB_PASS:-CHANGE_ME}',
    ],
    'session' => ['lifetime' => 3600, 'name' => 'PII_SESSION'],
    'paths' => [
        'storage' => __DIR__ . '/../storage',
        'logs'    => __DIR__ . '/../storage/logs',
        'cache'   => __DIR__ . '/../storage/cache',
        'exports' => __DIR__ . '/../storage/exports',
    ],
    'company' => ['name' => '$COMPANY_NAME'],
];
CONFIGEOF
    chmod 640 "$INSTALL_DIR/config/config.php"
    chown www-data:www-data "$INSTALL_DIR/config/config.php"

    say "Composer dependencies…"
    sudo -u www-data composer install -d "$INSTALL_DIR" --no-dev -o --quiet || fail "composer install failed."

    say "Migrations + admin user…"
    sudo -u www-data php "$INSTALL_DIR/migrations/migrate.php" || fail "Migrations failed."
    sudo -u www-data php "$INSTALL_DIR/scripts/create-admin.php" "$ADMIN_USER" "$ADMIN_PASS" "$ADMIN_USER" \
        || fail "Admin creation failed."

    say "Apache vhost…"
    cat > /etc/apache2/sites-available/precision-ink-insights.conf <<APACHEEOF
<VirtualHost *:80>
    ServerName $SERVER_NAME
    DocumentRoot $INSTALL_DIR/public
    <Directory $INSTALL_DIR/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <DirectoryMatch "^$INSTALL_DIR/(bin|config|docs|migrations|src|storage|tests|vendor)">
        Require all denied
    </DirectoryMatch>
    php_value upload_max_filesize 50M
    php_value post_max_size 50M
    php_value max_execution_time 600
    php_value memory_limit 1024M
    ErrorLog \${APACHE_LOG_DIR}/precision-ink-insights-error.log
    CustomLog \${APACHE_LOG_DIR}/precision-ink-insights-access.log combined
</VirtualHost>
APACHEEOF
    a2dissite 000-default.conf >/dev/null 2>&1 || true
    a2ensite precision-ink-insights.conf >/dev/null
    apache2ctl configtest 2>/dev/null || warn "Apache configtest reported a problem — check manually."
    svc restart apache2

    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q 'Status: active'; then
        ufw allow 80/tcp >/dev/null 2>&1 || true
        ufw allow 22/tcp >/dev/null 2>&1 || true
        okay "UFW: ports 80 and 22 allowed."
    fi
fi

# ============================================================
#  MAINTENANCE  (fresh install AND every update)
# ============================================================
say "File permissions…"
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R 775 "$INSTALL_DIR/storage"

say "Log rotation…"
cp "$INSTALL_DIR/installer/logrotate.conf" /etc/logrotate.d/precision-ink-insights
LR_ERRORS=$(logrotate -d /etc/logrotate.d/precision-ink-insights 2>&1 | grep '^error:' || true)
[ -z "$LR_ERRORS" ] && okay "logrotate config valid." || warn "logrotate: $LR_ERRORS"

say "Nightly inventory-snapshot cron…"
TMP_CRON=$(mktemp)
crontab -u www-data -l 2>/dev/null | grep -v 'Precision Ink Insights' | grep -v 'snapshot-inventory' > "$TMP_CRON" || true
{
    echo "# Precision Ink Insights - nightly inventory snapshot (approx 45s per day)"
    echo "0 3 * * * cd $INSTALL_DIR && /usr/bin/php cron/snapshot-inventory.php >> storage/logs/snapshot-inventory.log 2>&1"
} >> "$TMP_CRON"
crontab -u www-data "$TMP_CRON" || warn "crontab rejected the generated file."
rm -f "$TMP_CRON"
crontab -u www-data -l 2>/dev/null | grep -q 'snapshot-inventory.php' \
    && okay "Snapshot cron verified." || warn "Snapshot cron NOT installed — snapshots won't run."

say "Sync worker service…"
cat > /etc/systemd/system/insights-sync-worker.service <<UNITEOF
[Unit]
Description=Precision Ink Insights CMS sync worker
After=network.target mariadb.service
Wants=mariadb.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$INSTALL_DIR
ExecStart=/usr/bin/php $INSTALL_DIR/bin/sync-worker.php
Restart=always
RestartSec=5
StandardOutput=append:$INSTALL_DIR/storage/logs/sync-worker.log
StandardError=append:$INSTALL_DIR/storage/logs/sync-worker.log

[Install]
WantedBy=multi-user.target
UNITEOF
sctl daemon-reload
sctl enable insights-sync-worker >/dev/null 2>&1
sctl restart insights-sync-worker
[ $HAS_SYSTEMD -eq 1 ] && { systemctl is-active --quiet insights-sync-worker && okay "Sync worker running." || warn "Sync worker NOT running — journalctl -u insights-sync-worker"; }

say "Nightly database backup timer…"
mkdir -p "$BACKUP_DIR"
chown root:www-data "$BACKUP_DIR"; chmod 750 "$BACKUP_DIR"
cat > /etc/systemd/system/insights-backup.service <<'UNITEOF'
[Unit]
Description=Precision Ink Insights nightly database backup

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/insights-helper backup
UNITEOF
cat > /etc/systemd/system/insights-backup.timer <<'UNITEOF'
[Unit]
Description=Nightly Precision Ink Insights backup

[Timer]
OnCalendar=*-*-* 03:30:00
Persistent=true

[Install]
WantedBy=timers.target
UNITEOF
sctl daemon-reload
sctl enable --now insights-backup.timer >/dev/null 2>&1
okay "Backup timer installed (03:30 nightly, 30 days kept, snapshot tables excluded)."

say "Privileged helper + update wrapper…"
cat > "$HELPER" <<'HELPEREOF'
#!/bin/bash
# insights-helper — the ONLY privileged entry point the web app may call
# (via a fixed sudoers rule). No shell passthrough: a strict verb
# whitelist, each with fixed semantics. SPEC §5.
set -u
INSTALL_DIR="/var/www/precision-ink-insights"
BACKUP_DIR="/var/backups/insights"

verb="${1:-}"
case "$verb" in
    update)
        exec bash "$INSTALL_DIR/install.sh" --unattended
        ;;
    backup)
        mkdir -p "$BACKUP_DIR"; chown root:www-data "$BACKUP_DIR"; chmod 750 "$BACKUP_DIR"
        STAMP=$(date +%Y%m%d-%H%M%S)
        DB=$(php -r '$c=require "'"$INSTALL_DIR"'/config/config.php"; echo $c["db"]["name"];')
        IGNORE=""
        for t in or_items or_inventory or_orders or_order_lines or_tickets or_ticket_links or_coverage_results or_production_suggestions; do
            IGNORE="$IGNORE --ignore-table=$DB.$t"
        done
        php -r '$c=require "'"$INSTALL_DIR"'/config/config.php";
            file_put_contents("/tmp/insights-my.cnf", "[client]\nuser={$c["db"]["user"]}\npassword={$c["db"]["password"]}\nhost={$c["db"]["host"]}\n");
            chmod("/tmp/insights-my.cnf", 0600);'
        mysqldump --defaults-extra-file=/tmp/insights-my.cnf --single-transaction $IGNORE "$DB" \
            | gzip > "$BACKUP_DIR/insights-$STAMP.sql.gz"
        rm -f /tmp/insights-my.cnf
        chown root:www-data "$BACKUP_DIR/insights-$STAMP.sql.gz"; chmod 640 "$BACKUP_DIR/insights-$STAMP.sql.gz"
        find "$BACKUP_DIR" -name 'insights-*.sql.gz' -mtime +30 -delete
        echo "Backup written: $BACKUP_DIR/insights-$STAMP.sql.gz"
        ;;
    restart-services)
        systemctl reload apache2
        systemctl restart insights-sync-worker
        echo "Services restarted."
        ;;
    restart-sync-worker)
        systemctl restart insights-sync-worker
        echo "Sync worker restarted."
        ;;
    set-cms-credentials)
        # Reads JSON on stdin: {"host":"","name":"","user":"","password":""}
        php -r '
            $in = json_decode(stream_get_contents(STDIN), true);
            if (!is_array($in) || empty($in["host"]) || empty($in["user"])) { fwrite(STDERR, "Invalid JSON input.\n"); exit(1); }
            $cfg = ["host" => (string)$in["host"], "port" => 1433,
                    "name" => (string)($in["name"] ?: "CMS"),
                    "user" => (string)$in["user"], "password" => (string)($in["password"] ?? ""),
                    "trust_server_certificate" => !empty($in["trust_server_certificate"])];
            $f = "'"$INSTALL_DIR"'/config/cms.local.php";
            file_put_contents($f, "<?php\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX);
            chmod($f, 0640); chown($f, "www-data"); chgrp($f, "www-data");
            echo "CMS credentials written.\n";'
        ;;
    set-network)
        IFACE="${2:-}"; CIDR="${3:-}"; GW="${4:-}"; shift 4 2>/dev/null || { echo "Usage: set-network <iface> <ip/cidr> <gw> <dns…>"; exit 2; }
        DNSLIST=$(printf '%s, ' "$@"); DNSLIST="${DNSLIST%, }"
        [[ "$IFACE" =~ ^[a-z0-9]+$ ]] || { echo "Bad interface."; exit 2; }
        [[ "$CIDR" =~ ^[0-9.]+/[0-9]+$ ]] || { echo "Bad ip/cidr."; exit 2; }
        [[ "$GW" =~ ^[0-9.]+$ ]] || { echo "Bad gateway."; exit 2; }
        cat > /etc/netplan/60-insights.yaml <<NETEOF
network:
  version: 2
  ethernets:
    $IFACE:
      dhcp4: false
      addresses: [$CIDR]
      routes:
        - to: default
          via: $GW
      nameservers:
        addresses: [$DNSLIST]
NETEOF
        chmod 600 /etc/netplan/60-insights.yaml
        netplan generate || { echo "netplan validation FAILED — not applying."; rm -f /etc/netplan/60-insights.yaml; exit 1; }
        echo "Applying network config in 10 seconds — an SSH session on the old address will drop."
        sleep 10
        netplan apply
        echo "Network applied. New address: $CIDR"
        ;;
    *)
        echo "insights-helper: unknown verb '${verb}'."
        echo "Allowed: update | backup | restart-services | restart-sync-worker | set-cms-credentials | set-network"
        exit 2
        ;;
esac
HELPEREOF
chmod 750 "$HELPER"

cat > /usr/local/sbin/insights-update <<WRAPEOF
#!/bin/bash
exec bash "$INSTALL_DIR/install.sh" "\$@"
WRAPEOF
chmod 755 /usr/local/sbin/insights-update

cat > /etc/sudoers.d/insights <<'SUDOEOF'
# The Insights web app may run ONLY the fixed helper, nothing else.
www-data ALL=(root) NOPASSWD: /usr/local/sbin/insights-helper
SUDOEOF
chmod 440 /etc/sudoers.d/insights
visudo -cf /etc/sudoers.d/insights >/dev/null && okay "sudoers rule valid." || { rm -f /etc/sudoers.d/insights; warn "sudoers rule INVALID — removed."; }

# ── Optional static network (fresh + interactive only, applied LAST) ──
if [ $IS_UPDATE -eq 0 ] && [ $SKIP_NETWORK -eq 0 ] && [ $UNATTENDED -eq 0 ] && [ -t 0 ]; then
    echo
    read -rp "Configure a static IP now? [y/N] " _net
    if [[ "${_net,,}" == y* ]]; then
        echo "Interfaces:"; ip -o link show | awk -F': ' '{print "  " $2}' | grep -v lo
        read -rp "Interface: " N_IFACE
        read -rp "IP/CIDR (e.g. 10.10.10.50/24): " N_CIDR
        read -rp "Gateway: " N_GW
        read -rp "DNS (space-separated) [10.10.10.1 8.8.8.8]: " N_DNS
        "$HELPER" set-network "$N_IFACE" "$N_CIDR" "$N_GW" ${N_DNS:-10.10.10.1 8.8.8.8} || warn "Network config failed — still on DHCP."
    fi
fi

if [ $IS_UPDATE -eq 1 ]; then
    svc reload apache2
    NEWREV=$(sudo -u www-data git -C "$INSTALL_DIR" rev-parse --short HEAD)
    say "Update complete → $NEWREV"
else
    IP=$(hostname -I 2>/dev/null | awk '{print $1}')
    echo
    say "Install complete."
    echo "  URL:        http://${IP:-$SERVER_NAME}/"
    echo "  Admin user: $ADMIN_USER"
    [ "${GENERATED_PASS:-0}" = "1" ] && echo "  Admin pass: $ADMIN_PASS   (GENERATED — change it at first login)"
    echo "  Config:     $INSTALL_DIR/config/config.php"
    echo "  Logs:       $INSTALL_DIR/storage/logs/ and /var/log/apache2/"
    echo "  Update:     sudo insights-update"
fi
