#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/fbads}"
BRANCH="${BRANCH:-main}"

cd "$APP_DIR"

GIT_SSH_COMMAND='ssh -i /root/.ssh/id_ed25519_github -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -p 443' \
  git pull --ff-only origin "$BRANCH"

php -l index.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
