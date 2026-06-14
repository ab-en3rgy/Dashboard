# FB Ads Dashboard - Handoff for Next Chat

## Current State
- Dashboard is already deployed on the VPS.
- PostgreSQL database is migrated and working on the server.
- Server clone is connected to GitHub and update flow is already set up.
- Automatic backups are already configured with cron.
- The current server-side app root is `/var/www/fbads`.
- Apache serves the app from that directory.

## Server Details
- VPS IP: `149.33.41.79`
- OS: Ubuntu 22.04
- Web root: `/var/www/fbads`
- Database: `fb_ads`
- DB user: `fb_ads_user`
- Backup location: `/var/backups/fbads`

## Access / Secrets
- SSH access to the server exists from the local machine via the private key at `C:\Users\user\Key`.
- GitHub access on the server uses a deploy key at `/root/.ssh/id_ed25519_github`.
- App secrets and DB credentials are stored on the server in `/var/www/fbads/config/local.env`.
- Do not paste raw secret values into future commits or public logs.

## Update Flow
1. Change code locally.
2. Commit and push to GitHub.
3. On the server run:
   - `cd /var/www/fbads && ./scripts/server/update.sh`
   - or `git pull --ff-only origin main`
4. Verify with `php -l` / browser refresh.

## Working Rule
- Make changes in the local repository first.
- Treat the VPS as the deployment target, not the primary edit location.
- After local validation, push to GitHub and update the VPS from `main`.
- If an emergency hotfix is ever made on the server, mirror it back into the local repo immediately so the branches do not drift.
- Prefer small, targeted changes and verify them locally before deployment.

## Backup Flow
1. Manual backup:
   - `bash /var/www/fbads/scripts/server/backup.sh`
2. Backups are written to:
   - `/var/backups/fbads/<timestamp>/`
3. Latest backup is linked by:
   - `/var/backups/fbads/latest`
4. Backup includes:
   - database dump
   - app files
   - relevant config files

## Restore Flow
1. Restore from a chosen backup folder:
   - `bash /var/www/fbads/scripts/server/restore.sh /var/backups/fbads/latest`
2. Restore brings back:
   - database
   - files needed for the dashboard
3. Check restore target carefully before running.

## Important Notes
- A runtime DB bootstrap issue caused HTTP 500 on the server before; it was traced to repeated function creation in `lib/DB.php`.
- The fix was to stop creating `campaign_geo()` on every request.
- Keep UI text in English.
- Prefer small, targeted changes.
- Increment the version on every code edit.

## What Can Be Done Later
- Connect a domain and HTTPS.
- Add more cron jobs if more background scripts appear.
- Optionally harden SSL/domain setup.

## Quick Prompt for a New Chat
Use this project and continue from the deployed VPS state:

`FB Ads Dashboard is already on the VPS at 149.33.41.79, DB is migrated, backups and update scripts exist, and the repo is linked to GitHub. Continue from there, check the current server state first, then verify the dashboard and any remaining deployment issues. Keep UI in English, make small targeted changes, and bump version on every code edit.`
