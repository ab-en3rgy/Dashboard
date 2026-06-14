#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/fbads}"
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/fbads}"
DB_NAME="${DB_NAME:-fb_ads}"
DB_USER="${DB_USER:-fb_ads_user}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"

TS="$(date -u +%Y%m%d-%H%M%S)"
DEST="$BACKUP_ROOT/$TS"

mkdir -p "$DEST"

pg_dump -Fc -h 127.0.0.1 -U "$DB_USER" -d "$DB_NAME" -f "$DEST/database.dump"

if [[ -d "$APP_DIR/uploads" ]]; then
  tar -C "$APP_DIR" -czf "$DEST/uploads.tar.gz" uploads
fi

if [[ -f "$APP_DIR/config/local.env" ]]; then
  cp "$APP_DIR/config/local.env" "$DEST/local.env"
fi

ln -sfn "$DEST" "$BACKUP_ROOT/latest"

find "$BACKUP_ROOT" -maxdepth 1 -mindepth 1 -type d -name '20*' -mtime +"$RETENTION_DAYS" -exec rm -rf {} +
