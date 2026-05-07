#!/usr/bin/env bash
set -euo pipefail

# Simple setup script for Debian/Ubuntu Linux VMs.
# Installs Apache + PHP and deploys index.php to the default web root.

if [ ! -f "index.php" ]; then
  echo "Error: index.php not found in the current directory."
  echo "Run this script from the project folder that contains index.php."
  exit 1
fi

if [ "${EUID}" -ne 0 ]; then
  SUDO="sudo"
else
  SUDO=""
fi

echo "Updating package lists..."
${SUDO} apt-get update -y

echo "Installing apache2 and php..."
${SUDO} apt-get install -y apache2 php libapache2-mod-php

echo "Enabling and starting Apache..."
${SUDO} systemctl enable apache2
${SUDO} systemctl restart apache2

echo "Copying index.php to /var/www/html..."
${SUDO} cp index.php /var/www/html/index.php
${SUDO} chown www-data:www-data /var/www/html/index.php
${SUDO} chmod 644 /var/www/html/index.php

echo "Setup complete."
echo "Open http://<your-vm-public-ip>/ in your browser."
