# Pre-Launch Deployment Checklist

Use this checklist before going live. Run through it top-to-bottom for every deployment.

---

## Environment Configuration

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_URL` set to production HTTPS domain (e.g. `https://yourdomain.com`)
- [ ] `APP_KEY` generated and set (`php artisan key:generate`)
- [ ] Database credentials configured and connection tested (`php artisan db:show`)
- [ ] Mail SMTP configured and tested (send a test password reset)
- [ ] HTTPS/SSL certificate installed and active
- [ ] `SANCTUM_STATEFUL_DOMAINS` updated if using a separate frontend domain

---

## Performance Optimization

- [ ] `composer install --no-dev --optimize-autoloader` executed
- [ ] `php artisan config:cache` executed (or `php artisan deploy:prod`)
- [ ] `php artisan route:cache` executed
- [ ] `php artisan view:cache` executed
- [ ] OPcache enabled on server (verify with `php -r "echo opcache_get_status()['opcache_enabled'] ? 'on' : 'off';"`)
- [ ] Gzip compression active (verify: `curl -I -H "Accept-Encoding: gzip" https://yourdomain.com`)
- [ ] Browser caching headers present for static assets
- [ ] File permissions correct: `storage/` and `bootstrap/cache/` dirs 755, files 644

---

## Database & Migrations

- [ ] Database created and accessible from the server
- [ ] All migrations executed without errors (`php artisan migrate --force`)
- [ ] Required seeders run if applicable (`php artisan db:seed --force`)
- [ ] Database backups configured (see [rollback.md](rollback.md))

---

## Queue & Background Jobs

- [ ] `QUEUE_CONNECTION=database` in `.env`
- [ ] Queue tables present (`jobs`, `failed_jobs`) — run `php artisan migrate` if missing
- [ ] Cron job configured: `*/5 * * * * cd /path/to/app && php artisan queue:work --stop-when-empty >> /dev/null 2>&1`
- [ ] Test email delivery end-to-end (registration confirmation or password reset)
- [ ] `php artisan queue:monitor` shows 0 failed jobs

---

## Security

- [ ] `.env` file is **not** publicly accessible (verify: `curl https://yourdomain.com/.env` returns 403/404)
- [ ] `storage/` directory is **not** publicly accessible
- [ ] `bootstrap/cache/` is **not** publicly accessible
- [ ] Security headers present in responses (`X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`)
- [ ] CSRF protection enabled (default in Laravel — do not disable)
- [ ] Rate limiting active on auth endpoints (default: 10/min register, 5/min login)
- [ ] All admin routes protected with `super_admin` middleware

---

## Testing & Monitoring

- [ ] Health check endpoint accessible and healthy: `curl https://yourdomain.com/api/health`
- [ ] All Pest tests passing locally: `./vendor/bin/pest --compact`
- [ ] Performance benchmark acceptable: `php artisan benchmark:run`
  - DB Query p95 ≤ 100ms
  - Model Creation p95 ≤ 50ms
  - Cache Ops p95 ≤ 10ms
  - API Endpoint p95 ≤ 300ms
- [ ] Error logging configured (`LOG_CHANNEL=single`, `LOG_LEVEL=warning`)
- [ ] Uptime monitoring configured (optional: UptimeRobot, Pingdom — use `/api/health` as probe URL)

---

## Documentation

- [ ] README updated with production URL
- [ ] API documentation accessible to team
- [ ] Team informed of deployment date and rollback plan
- [ ] This checklist completed and archived

---

## Post-Launch

- [ ] Monitor `storage/logs/laravel.log` for the first 24 hours
- [ ] Verify queue is processing: `php artisan queue:monitor` shows 0 pending after cron runs
- [ ] Verify email delivery (register a test account)
- [ ] Test all critical user flows: register → create workspace → invite member → create project
- [ ] Monitor `X-Response-Time` headers for unexpectedly slow endpoints
- [ ] Run `php artisan benchmark:run` after first traffic to compare against baseline

---

## See Also

- [o2switch.md](o2switch.md) — step-by-step for o2switch
- [shared-hosting.md](shared-hosting.md) — generic shared hosting guide
- [rollback.md](rollback.md) — rollback strategy if something goes wrong
