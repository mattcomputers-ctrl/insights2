#!/bin/bash
# ============================================================
# Precision Ink Insights — Automated Installer for Ubuntu Server
# Ubuntu Server 20.04, 22.04, or 24.04
#
# Installs Apache, MariaDB, PHP 8.x with the SQL Server PDO driver,
# composer dependencies, runs migrations, sets up the admin user,
# and configures the Apache vhost.
#
# Usage:  sudo bash install-ubuntu.sh
# ============================================================
set -e

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

print_header()  { echo -e "\n${BLUE}============================================================${NC}\n${BLUE}  $1${NC}\n${BLUE}============================================================${NC}\n"; }
print_step()    { echo -e "${GREEN}[*]${NC} $1"; }
print_warn()    { echo -e "${YELLOW}[!]${NC} $1"; }
print_error()   { echo -e "${RED}[ERROR]${NC} $1"; }
print_success() { echo -e "${GREEN}[OK]${NC} $1"; }
print_info()    { echo -e "${CYAN}[i]${NC} $1"; }

# ── Pre-flight ────────────────────────────────────────────────
if [ "$EUID" -ne 0 ]; then
    print_error "This script must be run as root (use: sudo bash install-ubuntu.sh)"
    exit 1
fi

if [ ! -f /etc/os-release ]; then
    print_error "Cannot detect OS. This script is designed for Ubuntu Server."
    exit 1
fi
. /etc/os-release
if [ "$ID" != "ubuntu" ]; then
    print_error "This script is designed for Ubuntu Server. Detected: $PRETTY_NAME"
    exit 1
fi
UBUNTU_MAJOR=$(echo "$VERSION_ID" | cut -d. -f1)
if [ "$UBUNTU_MAJOR" -lt 20 ]; then
    print_error "Ubuntu 20.04 or newer is required. Detected: $VERSION_ID"
    exit 1
fi

print_header "Precision Ink Insights — Installer"
echo "Installs the Precision Ink Insights executive dashboard system on $PRETTY_NAME."
echo "Apache + MariaDB + PHP 8.x will be installed if not already present."
echo

# ── Step 1: Gather config ─────────────────────────────────────
print_header "Step 1: Configuration"

DEFAULT_INSTALL_DIR="/var/www/precision-ink-insights"
read -rp "Install directory [$DEFAULT_INSTALL_DIR]: " INSTALL_DIR
INSTALL_DIR="${INSTALL_DIR:-$DEFAULT_INSTALL_DIR}"

echo
echo "Local MariaDB:"
read -rp "MariaDB root password (blank for socket auth on a fresh install): " -s MYSQL_ROOT_PASS
echo
DEFAULT_DB_NAME="precision_ink_insights"
read -rp "Database name [$DEFAULT_DB_NAME]: " DB_NAME
DB_NAME="${DB_NAME:-$DEFAULT_DB_NAME}"
DEFAULT_DB_USER="pii_user"
read -rp "Database user [$DEFAULT_DB_USER]: " DB_USER
DB_USER="${DB_USER:-$DEFAULT_DB_USER}"
DB_PASS_DEFAULT=$(openssl rand -base64 18 | tr -dc 'a-zA-Z0-9' | head -c 20)
read -rp "Database password [$DB_PASS_DEFAULT]: " DB_PASS
DB_PASS="${DB_PASS:-$DB_PASS_DEFAULT}"

echo
read -rp "Company name [Precision Ink Insights]: " COMPANY_NAME
COMPANY_NAME="${COMPANY_NAME:-Precision Ink Insights}"

DEFAULT_SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
[ -z "$DEFAULT_SERVER_IP" ] && DEFAULT_SERVER_IP=$(hostname -f 2>/dev/null || hostname)
read -rp "Server IP or hostname [$DEFAULT_SERVER_IP]: " SERVER_NAME
SERVER_NAME="${SERVER_NAME:-$DEFAULT_SERVER_IP}"

echo
echo "Initial administrator account:"
read -rp "Admin username [admin]: " ADMIN_USER
ADMIN_USER="${ADMIN_USER:-admin}"
while true; do
    read -rp "Admin password (min 8 chars): " -s ADMIN_PASS
    echo
    [ ${#ADMIN_PASS} -ge 8 ] && break
    print_warn "Password must be at least 8 characters."
done
read -rp "Admin display name [$ADMIN_USER]: " ADMIN_DISPLAY
ADMIN_DISPLAY="${ADMIN_DISPLAY:-$ADMIN_USER}"

read -rp "Timezone [America/New_York]: " TIMEZONE
TIMEZONE="${TIMEZONE:-America/New_York}"

echo
echo "CMS (MSSQL) database — read-only connection used by the analytics tabs."
echo "You can skip and configure later in config/config.php."
read -rp "CMS SQL Server hostname or IP [10.10.10.11, blank to skip]: " CMS_DB_HOST
CMS_DB_HOST="${CMS_DB_HOST:-10.10.10.11}"
if [ "$CMS_DB_HOST" = "skip" ] || [ -z "$CMS_DB_HOST" ]; then
    CMS_DB_HOST=""
fi
if [ -n "$CMS_DB_HOST" ]; then
    read -rp "CMS SQL Server port [1433]: " CMS_DB_PORT
    CMS_DB_PORT="${CMS_DB_PORT:-1433}"
    read -rp "CMS database name [CMS]: " CMS_DB_NAME
    CMS_DB_NAME="${CMS_DB_NAME:-CMS}"
    read -rp "CMS database user: " CMS_DB_USER
    read -rp "CMS database password: " -s CMS_DB_PASS
    echo
fi

echo
print_step "Configuration captured. Starting installation…"

# ── Step 2: System dependencies ──────────────────────────────
print_header "Step 2: Installing system dependencies"

apt-get update -qq
export DEBIAN_FRONTEND=noninteractive

if ! command -v apache2 >/dev/null; then
    print_step "Installing Apache…"
    apt-get install -y -qq apache2 >/dev/null
    print_success "Apache installed."
else
    print_success "Apache already installed."
fi

if ! command -v mariadb >/dev/null && ! command -v mysql >/dev/null; then
    print_step "Installing MariaDB…"
    apt-get install -y -qq mariadb-server mariadb-client >/dev/null
    systemctl start mariadb
    systemctl enable mariadb >/dev/null
    print_success "MariaDB installed."

    if [ -n "$MYSQL_ROOT_PASS" ]; then
        mariadb -u root <<ROOTSQL
ALTER USER 'root'@'localhost' IDENTIFIED BY '$MYSQL_ROOT_PASS';
FLUSH PRIVILEGES;
ROOTSQL
        print_success "MariaDB root password set."
    fi
else
    print_success "MariaDB/MySQL already installed."
fi

# Detect / install PHP 8
PHP_INSTALLED=false
if command -v php >/dev/null; then
    PHP_VER=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;" 2>/dev/null || echo "0.0")
    PHP_MAJOR=$(echo "$PHP_VER" | cut -d. -f1)
    if [ "$PHP_MAJOR" -ge 8 ]; then
        print_success "PHP $PHP_VER already installed."
        PHP_INSTALLED=true
    fi
fi

if [ "$PHP_INSTALLED" = false ]; then
    print_step "Installing PHP and extensions…"
    if [ "$UBUNTU_MAJOR" -le 20 ]; then
        apt-get install -y -qq software-properties-common >/dev/null
        add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1
        apt-get update -qq
        PHP_PKG_VER="8.2"
    elif [ "$UBUNTU_MAJOR" -le 22 ]; then
        PHP_PKG_VER="8.1"
    else
        PHP_PKG_VER="8.3"
    fi
    apt-get install -y -qq \
        "php${PHP_PKG_VER}" "php${PHP_PKG_VER}-cli" \
        "php${PHP_PKG_VER}-mysql" "php${PHP_PKG_VER}-mbstring" \
        "php${PHP_PKG_VER}-xml" "php${PHP_PKG_VER}-curl" \
        "php${PHP_PKG_VER}-zip" "php${PHP_PKG_VER}-gd" \
        "php${PHP_PKG_VER}-intl" "php${PHP_PKG_VER}-bcmath" \
        "php${PHP_PKG_VER}-fileinfo" \
        "libapache2-mod-php${PHP_PKG_VER}" >/dev/null
    print_success "PHP $PHP_PKG_VER installed."
fi

# SQL Server PDO driver (dblib via FreeTDS)
print_step "Installing SQL Server PDO driver (FreeTDS / pdo_dblib)…"
CURRENT_PHP_VER=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;" 2>/dev/null)
apt-get install -y -qq "php${CURRENT_PHP_VER}-sybase" >/dev/null 2>&1 || \
    apt-get install -y -qq php-sybase >/dev/null 2>&1 || true

if php -m 2>/dev/null | grep -qi 'pdo_dblib\|pdo_sqlsrv'; then
    print_success "SQL Server PDO driver installed."
else
    print_warn "No SQL Server PDO driver detected. Margin Watchdog will not run until one is installed."
fi

# Composer
if ! command -v composer >/dev/null; then
    print_step "Installing Composer…"
    EXPECTED_CHECKSUM="$(php -r 'copy("https://composer.github.io/installer.sig", "php://stdout");')"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
    [ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ] && print_warn "Composer installer checksum mismatch — proceeding anyway."
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f composer-setup.php
    print_success "Composer installed."
fi

apt-get install -y -qq unzip curl git openssl >/dev/null
a2enmod rewrite >/dev/null 2>&1 || true
a2enmod headers >/dev/null 2>&1 || true

# ── Step 3: Application files ────────────────────────────────
print_header "Step 3: Setting up application files"

if [ -d "$INSTALL_DIR" ] && [ -f "$INSTALL_DIR/composer.json" ]; then
    print_step "Application directory exists — refreshing files (preserving config)…"
    SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
    rsync -a --exclude='vendor' --exclude='config/config.php' \
              --exclude='storage/logs/*' --exclude='storage/cache/*' \
              "$SCRIPT_DIR/" "$INSTALL_DIR/"
else
    SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
    if [ -f "$SCRIPT_DIR/composer.json" ] && [ -d "$SCRIPT_DIR/src" ]; then
        print_step "Copying application files to $INSTALL_DIR…"
        mkdir -p "$INSTALL_DIR"
        rsync -a --exclude='vendor' --exclude='config/config.php' \
                  "$SCRIPT_DIR/" "$INSTALL_DIR/"
    else
        print_error "Cannot find application source. Run this from the project root."
        exit 1
    fi
fi

cd "$INSTALL_DIR"
print_step "Installing PHP dependencies via composer…"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader 2>&1

mkdir -p storage/logs storage/cache storage/exports
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R 755 "$INSTALL_DIR"
chmod -R 775 storage

# ── Step 4: Configuration file ────────────────────────────────
print_header "Step 4: Writing configuration"

CONFIG_FILE="$INSTALL_DIR/config/config.php"
if [ -f "$CONFIG_FILE" ]; then
    print_warn "Configuration file already exists — leaving it untouched."
    print_info "Delete it and re-run the installer to regenerate."
else
cat > "$CONFIG_FILE" << CONFIGEOF
<?php
/**
 * Precision Ink Insights — generated by install-ubuntu.sh on $(date '+%Y-%m-%d %H:%M:%S')
 */
return [
    'app' => [
        'name'     => '$COMPANY_NAME',
        'url'      => 'http://$SERVER_NAME',
        'debug'    => false,
        'timezone' => '$TIMEZONE',
        'version'  => '1.0.0',
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => '$DB_NAME',
        'user'     => '$DB_USER',
        'password' => '$DB_PASS',
        'charset'  => 'utf8mb4',
    ],

    'cms_db' => [
        'host'     => '${CMS_DB_HOST}',
        'port'     => ${CMS_DB_PORT:-1433},
        'name'     => '${CMS_DB_NAME:-CMS}',
        'user'     => '${CMS_DB_USER}',
        'password' => '${CMS_DB_PASS}',
    ],

    'session' => [
        'lifetime' => 3600,
        'name'     => 'PII_SESSION',
    ],

    'paths' => [
        'storage' => __DIR__ . '/../storage',
        'logs'    => __DIR__ . '/../storage/logs',
        'cache'   => __DIR__ . '/../storage/cache',
        'exports' => __DIR__ . '/../storage/exports',
    ],

    'company' => [
        'name' => '$COMPANY_NAME',
    ],
];
CONFIGEOF
chmod 640 "$CONFIG_FILE"
chown www-data:www-data "$CONFIG_FILE"
print_success "Configuration file created."
fi

# ── Step 5: Database ──────────────────────────────────────────
print_header "Step 5: Setting up the local database"

if [ -n "$MYSQL_ROOT_PASS" ]; then
    MYSQL_AUTH="-u root -p$MYSQL_ROOT_PASS"
else
    MYSQL_AUTH=""
fi

mariadb $MYSQL_AUTH << SQLEOF
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQLEOF
print_success "Database and user created."

print_step "Running migrations…"
cd "$INSTALL_DIR" && php migrations/migrate.php

# Admin user
print_step "Creating admin user…"
ADMIN_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_ARGON2ID);' -- "$ADMIN_PASS")
mariadb $MYSQL_AUTH "$DB_NAME" << ADMINEOF
INSERT INTO users (username, email, password_hash, display_name, is_active)
VALUES ('$ADMIN_USER', NULL, '$ADMIN_HASH', '$ADMIN_DISPLAY', 1)
ON DUPLICATE KEY UPDATE password_hash='$ADMIN_HASH', is_active=1, display_name='$ADMIN_DISPLAY';

INSERT IGNORE INTO user_group_members (user_id, group_id)
SELECT u.id, g.id
FROM users u, permission_groups g
WHERE u.username = '$ADMIN_USER' AND g.name = 'Administrators';

-- Default Standard Users group: read on margin_watchdog
INSERT IGNORE INTO group_permissions (group_id, page_key, access_level)
SELECT g.id, 'margin_watchdog', 'read'
FROM permission_groups g
WHERE g.name = 'Standard Users';
ADMINEOF

print_success "Admin user '$ADMIN_USER' created and assigned to Administrators."

# ── Step 6: Apache vhost ──────────────────────────────────────
print_header "Step 6: Configuring Apache"

APACHE_CONF="/etc/apache2/sites-available/precision-ink-insights.conf"
cat > "$APACHE_CONF" << APACHEEOF
<VirtualHost *:80>
    ServerName $SERVER_NAME
    DocumentRoot $INSTALL_DIR/public

    <Directory $INSTALL_DIR/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <DirectoryMatch "^$INSTALL_DIR/(config|migrations|src|storage|vendor)">
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
systemctl enable apache2 >/dev/null
systemctl restart apache2
print_success "Apache configured and restarted."

# ── Step 6b: Cron — nightly inventory snapshot ────────────────
print_header "Step 6b: Setting up cron"

# File-based install, strictly sequential — the piped subshell form
# failed silently on some cron builds (and non-ASCII comments do too).
TMP_CRON=$(mktemp)
crontab -u www-data -l 2>/dev/null | grep -v 'Precision Ink Insights' | grep -v 'snapshot-inventory' > "$TMP_CRON" || true
{
    echo "# Precision Ink Insights - nightly inventory snapshot (approx 45s per day)"
    echo "0 3 * * * cd $INSTALL_DIR && /usr/bin/php cron/snapshot-inventory.php >> storage/logs/snapshot-inventory.log 2>&1"
} >> "$TMP_CRON"
crontab -u www-data "$TMP_CRON" || print_warn "crontab rejected the generated file."
rm -f "$TMP_CRON"

# Verify it actually persisted.
if crontab -u www-data -l 2>/dev/null | grep -q 'snapshot-inventory.php'; then
    print_success "Nightly inventory snapshot cron installed and verified (runs 03:00)."
else
    print_warn "Cron entry not present after install. Snapshots will NOT run automatically."
    print_warn "Diagnose: printf '* * * * * true\\n' | sudo crontab -u www-data - ; echo exit=\$?"
fi

# ── Step 6b2: Log rotation ────────────────────────────────────
LOGROTATE_SRC="$INSTALL_DIR/installer/logrotate.conf"
LOGROTATE_DST="/etc/logrotate.d/precision-ink-insights"
if [ -f "$LOGROTATE_SRC" ]; then
    print_step "Installing logrotate config…"
    # Substitute the install dir in case it's not the default
    sed "s|/var/www/precision-ink-insights|$INSTALL_DIR|g" "$LOGROTATE_SRC" > "$LOGROTATE_DST"
    chmod 0644 "$LOGROTATE_DST"
    chown root:root "$LOGROTATE_DST"
    # Parser check — logrotate -d returns 0 even on parse errors, so grep for them.
    LOGROTATE_ERRORS=$(logrotate -d "$LOGROTATE_DST" 2>&1 | grep '^error:' || true)
    if [ -n "$LOGROTATE_ERRORS" ]; then
        print_warn "Logrotate config has parser errors — log rotation will silently fail nightly:"
        echo "$LOGROTATE_ERRORS" | sed 's/^/    /'
    else
        print_success "Logrotate config installed and validated (daily, 14-day retention, gzip)."
    fi
fi

# ── Step 6c: Initial 30-day inventory backfill (background) ───
# Each call to GetInventoryAtDate takes ~45s, so 30 days = ~25 minutes.
# We background it so the installer doesn't block the user.
print_step "Starting 30-day inventory backfill in the background…"
nohup sudo -u www-data /usr/bin/php "$INSTALL_DIR/cron/snapshot-inventory.php" --backfill-days=30 \
    >> "$INSTALL_DIR/storage/logs/snapshot-inventory.log" 2>&1 &
print_info "Backfill PID: $!  ·  log: $INSTALL_DIR/storage/logs/snapshot-inventory.log"
print_info "The dashboard will show full 30-day comparison data once backfill finishes (~25 minutes)."

# Final permissions
print_step "Final file permissions…"
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R 755 "$INSTALL_DIR"
chmod -R 775 "$INSTALL_DIR/storage"
[ -f "$CONFIG_FILE" ] && chmod 640 "$CONFIG_FILE" && chown www-data:www-data "$CONFIG_FILE"

# Firewall
if command -v ufw >/dev/null; then
    print_step "Configuring UFW firewall (HTTP + SSH)…"
    ufw allow 'Apache Full' >/dev/null 2>&1 || ufw allow 80/tcp >/dev/null 2>&1
    ufw allow 22/tcp >/dev/null 2>&1
    if ufw status | grep -q "inactive"; then
        echo "y" | ufw enable >/dev/null 2>&1 || true
    fi
fi

# ── Verification ──────────────────────────────────────────────
print_header "Verification"
ERRS=0
systemctl is-active --quiet apache2 && print_success "Apache running." || { print_error "Apache NOT running."; ERRS=$((ERRS+1)); }
( systemctl is-active --quiet mariadb || systemctl is-active --quiet mysql ) && print_success "MariaDB/MySQL running." || { print_error "MariaDB/MySQL NOT running."; ERRS=$((ERRS+1)); }
mariadb $MYSQL_AUTH -e "SELECT 1 FROM \`$DB_NAME\`.users LIMIT 1" >/dev/null 2>&1 && print_success "Database reachable." || { print_error "Cannot reach database."; ERRS=$((ERRS+1)); }
[ "$ERRS" -gt 0 ] && print_warn "$ERRS issue(s) detected."

# ── Done ──────────────────────────────────────────────────────
print_header "Installation complete!"
echo -e "${GREEN}URL:${NC}       http://$SERVER_NAME"
echo -e "${GREEN}Username:${NC}  $ADMIN_USER"
echo -e "${GREEN}Password:${NC}  (the one you entered)"
echo
echo "Install dir:        $INSTALL_DIR"
echo "Database:           $DB_NAME (user $DB_USER)"
echo "Config file:        $CONFIG_FILE"
echo
echo -e "${YELLOW}Next steps:${NC}"
echo "  1. Sign in at http://$SERVER_NAME"
echo "  2. Confirm/edit CMS connection in $CONFIG_FILE if needed"
echo "  3. Verify CMS query results in the Margin Watchdog tab"
echo "  4. Add more users via Admin → Users"
echo
echo -e "${YELLOW}HTTPS (recommended):${NC}"
echo "  sudo apt install certbot python3-certbot-apache"
echo "  sudo certbot --apache -d $SERVER_NAME"
echo
