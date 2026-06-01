# Deploying SaaSForge to o2switch

This guide walks through deploying your SaaS to o2switch mutualisé hosting (cPanel).

---

## Prerequisites

- o2switch account with cPanel access
- Domain configured and pointing to o2switch nameservers
- SSH access enabled (contact o2switch support if not already active)
- Git available on the server (pre-installed on o2switch)

> Security baseline: keep all sensitive values in GitHub Secrets and/or server `.env` only.  
> See [`docs/deployment/secrets-management.md`](./secrets-management.md).

---

## Step 1: Create MySQL Database

1. Log into **cPanel** → **MySQL Databases**
2. Create a new database: `username_saasforge`
3. Create a new user: `username_saas` with a strong password
4. Grant **ALL PRIVILEGES** to `username_saas` on `username_saasforge`
5. Note: the hostname is always `localhost` on o2switch

---

## Step 2: Upload Code via Git

```bash
# SSH into your o2switch account
ssh username@ssh.o2switch.net

# Navigate to your domain's directory
cd www/yourdomain.com

# Clone your repository (use HTTPS to avoid SSH key setup)
git clone https://github.com/yourusername/your-saas.git .
```

> **Alternative (no Git):** Upload all files via FTP, excluding `vendor/`, `.git/`, and `.env`.

---

## Step 3: Install Dependencies

```bash
# Composer is pre-installed on o2switch
composer install --no-dev --optimize-autoloader

# If Composer is unavailable, run locally and upload the vendor/ folder via FTP
```

---

## Step 4: Configure Environment

```bash
# Copy the production template
cp .env.production.example .env

# Edit with your specific values
nano .env
```

Update the following values:

```env
APP_NAME="Your SaaS Name"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=username_saasforge
DB_USERNAME=username_saas
DB_PASSWORD=your_strong_password

CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=database

# o2switch SMTP (or use Resend/Mailgun)
MAIL_MAILER=smtp
MAIL_HOST=smtp.o2switch.net
MAIL_PORT=587
MAIL_USERNAME=noreply@yourdomain.com
MAIL_PASSWORD=your_email_password
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"
```

---

## Step 5: Run Migrations & Setup

```bash
# Generate a unique application key
php artisan key:generate

# Run all database migrations
php artisan migrate --force

# One-command production setup (clear + cache config/routes/views + storage:link)
php artisan deploy:prod

# Set correct file permissions
bash deploy.sh
```

---

## Step 6: Configure Web Root

In **cPanel → Domains → Domains**:

1. Edit your domain
2. Set **Document Root** to: `/home/username/www/yourdomain.com/public`
3. Save changes

> The `public/` directory is the only folder that should be publicly accessible.
> The `public/.htaccess` already handles routing and security headers.

---

## Step 7: Setup Queue Worker

o2switch supports cron jobs for background job processing.

In **cPanel → Cron Jobs**, add a new cron:

| Field | Value |
|-------|-------|
| Minute | `*/5` |
| Hour | `*` |
| Day | `*` |
| Month | `*` |
| Weekday | `*` |
| Command | `cd /home/username/www/yourdomain.com && php artisan queue:work --stop-when-empty >> /dev/null 2>&1` |

This processes queued jobs (invitation emails, password resets, etc.) every 5 minutes.

---

## Step 8: SSL Certificate

o2switch provides free **Let's Encrypt** SSL via AutoSSL:

1. In cPanel → **SSL/TLS Status**
2. Enable **AutoSSL** for your domain
3. Wait 5–10 minutes for certificate issuance
4. Verify HTTPS works: `https://yourdomain.com`

> Once SSL is active, update `APP_URL=https://yourdomain.com` in `.env` and run `php artisan config:cache`.

---

## Step 9: Test Deployment

```bash
# Check health endpoint (database + cache + storage)
curl https://yourdomain.com/api/health

# Expected healthy response:
# {"status":"healthy","checks":{"database":"ok","cache":"ok","storage":"ok"},"timestamp":"..."}

# Run performance benchmarks (optional)
php artisan benchmark:run --samples=10
```

---

## Updating Your Deployment

```bash
# SSH into server
ssh username@ssh.o2switch.net
cd www/yourdomain.com

# Pull latest code
git pull origin main

# Update PHP dependencies (if composer.json changed)
composer install --no-dev --optimize-autoloader

# Run new migrations
php artisan migrate --force

# Rebuild all caches
php artisan deploy:prod
```

---

## Troubleshooting

### "500 Internal Server Error"

Check Laravel logs:

```bash
tail -f storage/logs/laravel.log
```

Common causes:

- Missing `.env` file → run `cp .env.production.example .env` and fill in values
- Wrong permissions on `storage/` → run `bash deploy.sh`
- Database connection failure → double-check `DB_HOST=localhost` and credentials in `.env`
- Stale config cache → run `php artisan config:clear`

### "Queue not processing / emails not sending"

Verify the cron job is saved and active in cPanel. Test manually:

```bash
php artisan queue:work --once
```

Check failed jobs:

```bash
php artisan queue:monitor
php artisan queue:failed
```

Retry failed jobs:

```bash
php artisan queue:retry all
```

### "Slow performance"

1. Ensure caches are optimized:

   ```bash
   php artisan deploy:prod
   ```

2. Verify OPcache is enabled (contact o2switch support if unsure)

3. Run benchmark to identify bottlenecks:

   ```bash
   php artisan benchmark:run
   ```

### ".env not found after FTP upload"

The `.env` file may have been skipped (hidden files are sometimes excluded by FTP clients).
Enable "Show hidden files" in your FTP client settings and re-upload.

---

## Performance Tips

- ✅ **File cache** — no Redis dependency, works on all o2switch plans
- ✅ **Database queue** — reliable background processing without extra services
- ✅ **OPcache** — pre-enabled on o2switch, compiles PHP bytecode for 3–5× speed
- ✅ **Gzip compression** — configured in `public/.htaccess` (Story 8.1)
- ✅ **Browser caching** — configured in `public/.htaccess` for CSS/JS/images
- ✅ **Config/route/view cache** — applied by `php artisan deploy:prod`

---

## Cost Estimate

| Plan | Price | Suitable for |
|------|-------|-------------|
| o2switch Solo | ~€5/month HT (~€6 TTC) | 100–500 users, unlimited DB/storage/bandwidth |

Your SaaS is now live! 🎉
