#!/usr/bin/env bash
# install.sh – Bootstrap AIBox on a fresh machine.
#
# Usage: curl -fsSL <url>/install.sh | bash
#        or:  bash install.sh
#
# The script will:
#   1. Check for PHP 8.1+
#   2. Install Composer if missing
#   3. Run `composer install`
#   4. Execute `bin/aibox`

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

###############################################################################
# Helpers
###############################################################################

info()  { echo "[INFO]  $*"; }
warn()  { echo "[WARN]  $*" >&2; }
error() { echo "[ERROR] $*" >&2; exit 1; }

###############################################################################
# 1. PHP 8.1+
###############################################################################

info "Checking PHP version…"
if ! command -v php &>/dev/null; then
    error "PHP is not installed. Please install PHP 8.1 or newer and re-run this script."
fi

PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;")
PHP_MAJOR=$(php -r "echo PHP_MAJOR_VERSION;")
PHP_MINOR=$(php -r "echo PHP_MINOR_VERSION;")

if [[ "$PHP_MAJOR" -lt 8 ]] || ( [[ "$PHP_MAJOR" -eq 8 ]] && [[ "$PHP_MINOR" -lt 1 ]] ); then
    error "PHP 8.1+ is required. Detected: $PHP_VERSION"
fi

info "PHP $PHP_VERSION detected."

###############################################################################
# 2. Composer
###############################################################################

info "Checking for Composer…"
if command -v composer &>/dev/null; then
    COMPOSER_CMD="composer"
    info "Composer found: $(composer --version 2>/dev/null | head -1)"
elif [[ -f "$SCRIPT_DIR/composer.phar" ]]; then
    COMPOSER_CMD="php $SCRIPT_DIR/composer.phar"
    info "Using bundled composer.phar"
else
    info "Composer not found – downloading…"
    EXPECTED_CHECKSUM="$(php -r "copy('https://composer.github.io/installer.sig', 'php://stdout');")"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_CHECKSUM="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

    if [[ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]]; then
        rm -f composer-setup.php
        error "Composer installer checksum verification failed."
    fi

    php composer-setup.php --quiet --install-dir="$SCRIPT_DIR" --filename=composer.phar
    rm -f composer-setup.php
    COMPOSER_CMD="php $SCRIPT_DIR/composer.phar"
    info "Composer downloaded successfully."
fi

###############################################################################
# 3. Install PHP dependencies
###############################################################################

info "Installing PHP dependencies…"
(cd "$SCRIPT_DIR" && $COMPOSER_CMD install --no-interaction --prefer-dist --optimize-autoloader)

###############################################################################
# 4. Run AIBox
###############################################################################

info "Launching AIBox…"
echo ""
exec php "$SCRIPT_DIR/bin/aibox" "$@"
