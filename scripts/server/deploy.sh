#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/fbads}"
REPO_URL="${REPO_URL:-git@github.com:ab-en3rgy/Dashboard.git}"
BRANCH="${BRANCH:-main}"

if [[ ! -d "$APP_DIR/.git" ]]; then
  git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
fi

cd "$APP_DIR"
git fetch origin
git reset --hard "origin/$BRANCH"

php -l index.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
