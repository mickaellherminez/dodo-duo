# Deploying to Generic Shared Hosting

This guide applies to most **cPanel-based shared hosting providers**.
For o2switch specifically, see [o2switch.md](o2switch.md).

---

## Requirements Checklist

Before choosing a host, verify it provides:

- ✅ **PHP 8.2+** with extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`
- ✅ **MySQL 8.0+** or MariaDB 10.3+
- ✅ **Composer** access via SSH (or ability to upload `vendor/`)
- ✅ **SSH access** (preferred) or SFTP/FTP
- ✅ **Ability to set web root** to `/public` subdirectory
- ✅ **Cron job support** for queue processing (at least every 5 minutes)
- ✅ **Storage write access** for `storage/` and `bootstrap/cache/`

```bash
# Verify PHP version and extensions via SSH
php -v
php -m | grep -E "pdo_mysql|mbstring|openssl|tokenizer|xml|bcmath"
```

---

## Quick Start

1. **Database Setup** — Create MySQL database and user via cPanel → MySQL Databases
2. **Upload Code** — `git clone` via SSH or upload via FTP (excluding `vendor/`, `.git/`, `.env`)
3. **Dependencies** — `composer install --no-dev --optimize-autoloader`
4. **Environment** — Copy `.env.production.example` to `.env` and fill in DB, mail, and APP_URL values
5. **App Key** — `php artisan key:generate`
6. **Migrations** — `php artisan migrate --force`
7. **Deploy** — `php artisan deploy:prod` (clears + rebuilds all caches)
8. **Web Root** — Point domain's document root to the `/public` directory in cPanel
9. **Queue Cron** — Add cron: `*/5 * * * * cd /path/to/app && php artisan queue:work --stop-when-empty >> /dev/null 2>&1`
10. **SSL** — Enable Let's Encrypt via cPanel → SSL/TLS Status

---

## Host-Specific Notes

### Hostinger

- PHP 8.2+ available on all plans; select version in hPanel → PHP Configuration
- SSH access available on all paid plans
- Free SSL via Let's Encrypt (auto-provisioned)
- OPcache enabled by default
- Web root control: hPanel → Hosting → Manage → Files → Public directory

### SiteGround

- PHP 8.2+ available; configure in Site Tools → PHP Manager
- SSH access on **GrowBig** and higher plans
- Free Cloudflare CDN integration for improved global performance
- SuperCacher (server-side cache) — disable for API-only apps to avoid stale responses
- Web root: Site Tools → Site → Domains → set document root

### Namecheap

- PHP 8.2 available on **Stellar** and higher plans
- SSH access available (may need to enable in cPanel → SSH Access)
- Free SSL certificates via AutoSSL (Let's Encrypt)
- OPcache may need manual activation — contact support or enable in PHP settings
- Web root: cPanel → Domains → set document root to `public/`

### PlanetHoster (World)

- PHP 8.2+ with extensive extension support
- SSH and Git pre-installed
- Free SSL, unlimited MySQL databases
- OPcache and Redis available on N0C plans

---

## Common Issues

### Storage permission errors

```bash
chmod -R 755 storage bootstrap/cache
find storage -type f -exec chmod 644 {} \;
```

Or use the provided script: `bash deploy.sh`

### "No application encryption key"

```bash
php artisan key:generate
php artisan config:cache
```

### Composer not found on server

Run `composer install` locally and upload the entire `vendor/` directory via FTP.
This is slower but works on any host.

### White screen / 500 after deployment

```bash
# Clear all caches
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Check logs
tail -50 storage/logs/laravel.log
```

### `.htaccess` not working (mod_rewrite disabled)

Contact your host to enable `mod_rewrite`. It is required for Laravel routing.
All requests must be directed through `public/index.php`.

### Cron job not running

Verify by adding a simple test cron first:

```
* * * * * echo "cron ok" >> /tmp/cron-test.log
```

Check the log after 1 minute. If empty, contact support to enable cron jobs.

---

## See Also

- [o2switch.md](o2switch.md) — detailed step-by-step for o2switch
- [checklist.md](checklist.md) — pre-launch checklist
- [rollback.md](rollback.md) — rollback strategy
