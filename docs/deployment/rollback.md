# Rollback Strategy

This document covers three rollback approaches, from simplest to most advanced.
**Always backup the database before running migrations.**

---

## Quick Rollback via Git

Use this when the new code introduced a bug and you need to revert immediately.

```bash
# SSH into server
ssh username@ssh.o2switch.net
cd www/yourdomain.com

# Fetch all remote tags and branches
git fetch origin

# Option A: rollback to the previous version tag
git checkout v0.26.0  # replace with the last known-good tag

# Option B: rollback to a specific commit
git log --oneline -10   # identify the target commit hash
git checkout abc1234

# Reinstall dependencies for that version
composer install --no-dev --optimize-autoloader

# Rollback the last migration batch (only if the new version added migrations)
php artisan migrate:rollback --step=1

# Rebuild caches for the rolled-back version
php artisan deploy:prod

# IMPORTANT: return to the main branch to resume normal deployments.
# git checkout <tag> creates a "detached HEAD" — git pull does nothing in this state.
git checkout main
```

> After rollback, verify the health endpoint: `curl https://yourdomain.com/api/health`

---

## Database Backup Before Migrations

**Run this before every deployment that includes new migrations.**

```bash
# SSH into server
ssh username@ssh.o2switch.net
cd www/yourdomain.com

# Create a timestamped backup
mysqldump -u username_saas -p username_saasforge > backup_$(date +%Y%m%d_%H%M%S).sql

# Verify the backup file was created and is non-empty
ls -lh backup_*.sql
```

To restore from backup if migrations caused data loss:

```bash
# Stop the queue worker first (to avoid new jobs writing to the DB)
# Then restore:
mysql -u username_saas -p username_saasforge < backup_YYYYMMDD_HHMMSS.sql

# Rollback the migration code
git checkout v0.26.0
composer install --no-dev --optimize-autoloader
php artisan deploy:prod
```

> Store backups outside the web root and keep at least 3 recent backups.
> On o2switch, cPanel → **Backup Wizard** also provides automatic daily backups.

---

## Blue-Green Deployment (Zero Downtime)

Use this for critical production deployments where any downtime is unacceptable.

### Setup

```bash
# On your server, create two directories
mkdir -p /home/username/www/yourdomain.com-blue
mkdir -p /home/username/www/yourdomain.com-green

# Initial symlink points to blue
ln -sfn /home/username/www/yourdomain.com-blue /home/username/www/yourdomain.com-live
```

### Deploy to the inactive slot

```bash
# Identify which slot is currently live
readlink /home/username/www/yourdomain.com-live
# → .../yourdomain.com-blue  →  deploy to green

# Deploy new version to green
cd /home/username/www/yourdomain.com-green
git pull origin main
composer install --no-dev --optimize-autoloader
cp /home/username/www/yourdomain.com-blue/.env .env   # reuse the live .env
php artisan migrate --force
php artisan deploy:prod

# Test the new version on the inactive slot before switching
curl https://yourdomain.com-green.yourdomain.com/api/health
```

### Switch traffic

```bash
# Atomic symlink switch (near-zero downtime)
ln -sfn /home/username/www/yourdomain.com-green /home/username/www/yourdomain.com-live

# Verify live site is the new version
curl https://yourdomain.com/api/health
```

### Instant rollback

```bash
# If the new version has issues, switch back immediately
ln -sfn /home/username/www/yourdomain.com-blue /home/username/www/yourdomain.com-live
```

> Note: In cPanel environments, the Document Root must point to `yourdomain.com-live/public`.
> The symlink switch is effective immediately — no cPanel changes needed after initial setup.

---

## See Also

- [checklist.md](checklist.md) — pre-launch checklist
- [o2switch.md](o2switch.md) — deployment guide for o2switch
