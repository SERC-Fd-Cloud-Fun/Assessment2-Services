#!/usr/bin/env bash
# =============================================================================
# setup.sh — Provision Apache2 + PHP for the Open Day Registration app
#
# Usage:
#   sudo bash setup.sh [--connection-string "<Azure Storage connection string>"]
#
# The script must be run as root (or via sudo).
# It assumes this script lives in the project root directory.
# =============================================================================
set -euo pipefail

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
info()  { echo -e "\e[34m[INFO]\e[0m  $*"; }
ok()    { echo -e "\e[32m[ OK ]\e[0m  $*"; }
warn()  { echo -e "\e[33m[WARN]\e[0m  $*"; }
die()   { echo -e "\e[31m[FAIL]\e[0m  $*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Must run as root
# ---------------------------------------------------------------------------
[[ $EUID -eq 0 ]] || die "Please run this script with sudo or as root."

# ---------------------------------------------------------------------------
# Parse arguments
# ---------------------------------------------------------------------------
STORAGE_CONNECTION_STRING=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --connection-string)
            STORAGE_CONNECTION_STRING="$2"
            shift 2
            ;;
        *)
            die "Unknown argument: $1"
            ;;
    esac
done

# Resolve project root (directory containing this script)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_NAME="openday"
WEB_ROOT="/var/www/${APP_NAME}"
VHOST_CONF="/etc/apache2/sites-available/${APP_NAME}.conf"
ENV_CONF="/etc/apache2/conf-available/${APP_NAME}-env.conf"

info "Project source : ${SCRIPT_DIR}"
info "Web root       : ${WEB_ROOT}"

# ---------------------------------------------------------------------------
# 1. System update & package installation
# ---------------------------------------------------------------------------
info "Updating package lists..."
apt-get update -qq

info "Installing Apache2, PHP, and required extensions..."
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    apache2 \
    php \
    libapache2-mod-php \
    php-cli \
    php-curl \
    php-fileinfo \
    php-json \
    php-mbstring \
    php-xml \
    php-zip \
    unzip \
    curl

ok "Packages installed."

# ---------------------------------------------------------------------------
# 2. Install Composer
# ---------------------------------------------------------------------------
if command -v composer &>/dev/null; then
    ok "Composer already installed: $(composer --version --no-ansi 2>/dev/null | head -1)"
else
    info "Installing Composer..."
    EXPECTED_CHECKSUM="$(curl -sS https://composer.github.io/installer.sig)"
    curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"

    if [[ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]]; then
        rm -f /tmp/composer-setup.php
        die "Composer installer checksum mismatch — aborting."
    fi

    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
    ok "Composer installed: $(composer --version --no-ansi 2>/dev/null | head -1)"
fi

# ---------------------------------------------------------------------------
# 3. Deploy application files
# ---------------------------------------------------------------------------
info "Deploying application to ${WEB_ROOT}..."

mkdir -p "${WEB_ROOT}"

# Copy project files, excluding setup script, git directory, and any existing vendor/
rsync -a --exclude='setup.sh' \
         --exclude='.git' \
         --exclude='vendor' \
         "${SCRIPT_DIR}/" "${WEB_ROOT}/"

ok "Files copied."

# ---------------------------------------------------------------------------
# 4. Install PHP dependencies via Composer
# ---------------------------------------------------------------------------
info "Installing PHP dependencies..."
composer install \
    --working-dir="${WEB_ROOT}" \
    --no-interaction \
    --no-dev \
    --optimize-autoloader \
    --quiet

ok "Composer dependencies installed."

# ---------------------------------------------------------------------------
# 5. File permissions
# ---------------------------------------------------------------------------
info "Setting file permissions..."
chown -R www-data:www-data "${WEB_ROOT}"
find "${WEB_ROOT}" -type d -exec chmod 750 {} \;
find "${WEB_ROOT}" -type f -exec chmod 640 {} \;
ok "Permissions set."

# ---------------------------------------------------------------------------
# 6. Apache environment variables
# ---------------------------------------------------------------------------
info "Configuring Apache environment variables..."

if [[ -n "$STORAGE_CONNECTION_STRING" ]]; then
    cat > "${ENV_CONF}" <<EOF
# Auto-generated by setup.sh — do not edit manually.
# Azure Storage connection string for the Open Day Registration app.
SetEnv AZURE_STORAGE_CONNECTION_STRING "${STORAGE_CONNECTION_STRING}"
EOF
    ok "AZURE_STORAGE_CONNECTION_STRING written to ${ENV_CONF}."
else
    warn "--connection-string was not provided."
    warn "Create ${ENV_CONF} manually with:"
    warn '  SetEnv AZURE_STORAGE_CONNECTION_STRING "DefaultEndpointsProtocol=https;AccountName=...;..."'
fi

# Ensure the file exists so Apache does not fail on the Include.
if [[ ! -f "${ENV_CONF}" ]]; then
    echo "# AZURE_STORAGE_CONNECTION_STRING not yet configured — add it here." \
        > "${ENV_CONF}"
fi

# ---------------------------------------------------------------------------
# 7. Apache virtual host
# ---------------------------------------------------------------------------
info "Configuring Apache virtual host..."

cat > "${VHOST_CONF}" <<EOF
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot ${WEB_ROOT}

    <Directory ${WEB_ROOT}>
        Options -Indexes -FollowSymLinks
        AllowOverride None
        Require all granted

        # Route everything through index.php
        DirectoryIndex index.php
    </Directory>

    # Load environment variables for this app
    Include conf-available/${APP_NAME}-env.conf

    ErrorLog  \${APACHE_LOG_DIR}/${APP_NAME}-error.log
    CustomLog \${APACHE_LOG_DIR}/${APP_NAME}-access.log combined
</VirtualHost>
EOF

ok "Virtual host written to ${VHOST_CONF}."

# ---------------------------------------------------------------------------
# 8. Enable site, enable required modules, disable default site
# ---------------------------------------------------------------------------
info "Enabling Apache modules and site..."

a2enmod php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;') 2>/dev/null || true
a2enmod rewrite -q
a2enconf "${APP_NAME}-env" -q
a2ensite "${APP_NAME}" -q
a2dissite 000-default -q 2>/dev/null || true

ok "Site enabled."

# ---------------------------------------------------------------------------
# 9. Validate config and restart Apache
# ---------------------------------------------------------------------------
info "Validating Apache configuration..."
apache2ctl configtest

info "Restarting Apache..."
systemctl restart apache2
systemctl enable apache2 --quiet

ok "Apache is running."

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
echo ""
echo "=========================================="
ok "Setup complete!"
echo "  App deployed to : ${WEB_ROOT}"
echo "  Virtual host    : ${VHOST_CONF}"
echo "  Env conf        : ${ENV_CONF}"
echo ""
if [[ -z "$STORAGE_CONNECTION_STRING" ]]; then
    warn "Remember to add your Azure Storage connection string to:"
    warn "  ${ENV_CONF}"
    warn "Then run:  sudo systemctl restart apache2"
fi
echo "=========================================="
