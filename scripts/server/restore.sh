#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="${1:?Usage: restore.sh /path/to/backup}"
APP_DIR="${APP_DIR:-/var/www/fbads}"
DB_NAME="${DB_NAME:-fb_ads}"
DB_USER="${DB_USER:-fb_ads_user}"

pg_restore --clean --if-exists -h 127.0.0.1 -U "$DB_USER" -d "$DB_NAME" "$BACKUP_DIR/database.dump"

if [[ -f "$BACKUP_DIR/uploads.tar.gz" ]]; then
  tar -xzf "$BACKUP_DIR/uploads.tar.gz" -C "$APP_DIR"
fi

if [[ -f "$BACKUP_DIR/local.env" ]]; then
  install -m 600 "$BACKUP_DIR/local.env" "$APP_DIR/config/local.env"
fi
