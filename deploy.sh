#!/bin/bash
# deploy.sh — Set correct file permissions for shared hosting (mutualisé)
#
# Usage:
#   bash deploy.sh
#
# Run this script from the project root after uploading/pulling code.
# Required permissions for Laravel on shared hosting:
#   - Directories: 755 (owner rwx, group rx, other rx)
#   - Files:       644 (owner rw, group r, other r)
#
# storage/ and bootstrap/cache/ must be writable by the web server.

set -e

echo "Setting directory permissions..."

chmod -R 755 storage
chmod -R 755 bootstrap/cache

find storage -type f -exec chmod 644 {} \;
find storage -type d -exec chmod 755 {} \;

find bootstrap/cache -type f -exec chmod 644 {} \;
find bootstrap/cache -type d -exec chmod 755 {} \;

echo "✅ Permissions set"
echo ""
echo "Next steps:"
echo "  1. Copy .env.production.example to .env and fill in your values"
echo "  2. Run: composer install --no-dev --optimize-autoloader"
echo "  3. Run: php artisan key:generate"
echo "  4. Run: php artisan deploy:prod"
