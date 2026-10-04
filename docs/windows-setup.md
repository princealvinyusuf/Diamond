# Windows/XAMPP paper-production operations

## Install and configure

1. Install XAMPP with PHP 8.2+, Composer, Node.js, and Python 3.12. Enable Apache
   `mod_rewrite`, PHP `openssl`, `pdo_mysql`, `mbstring`, and `fileinfo`.
2. Put the repository outside a web-accessible parent when possible. Configure
   an Apache virtual host whose `DocumentRoot` is `Diamond\public`; never expose
   the repository root, `.env`, `storage`, fixtures, or backup directory.
3. Copy `.env.example` to `.env`, generate `APP_KEY` with
   `php artisan key:generate`, and set database/provider values only in `.env`.
   Never commit `.env`, API keys, database passwords, dumps, or logs.
4. For HTTPS set `APP_ENV=production`, `APP_DEBUG=false`, an HTTPS `APP_URL`,
   and `SESSION_SECURE_COOKIE=true`. Keep `SESSION_ENCRYPT=true`,
   `SESSION_SAME_SITE=lax`, `TRADING_MODE=paper`, and
   `LIVE_TRADING_ENABLED=false`.
5. Install and initialize:

   ```powershell
   composer install --no-dev --optimize-autoloader
   npm install
   npm run build
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

Laravel web middleware provides CSRF validation, encrypted HTTP-only sessions,
request IDs, CSP, clickjacking/MIME protections, and HSTS on non-local HTTPS.
Persistent mutation endpoints reject anonymous callers outside local/testing.
Authentication is intentionally not implemented yet, so those endpoints remain
fail-closed in production until an ownership-aware auth layer is added.

## Queue and scheduler

Run separate long-lived workers (Task Scheduler or a service wrapper such as
NSSM), both from the repository directory:

```powershell
php artisan queue:work --queue=analysis,backtests,default --tries=3 --timeout=310
php artisan schedule:work
```

Alternatively invoke `php artisan schedule:run` every minute from Windows Task
Scheduler. The H4 job is scheduled at minute 5 after each UTC four-hour
boundary. Queue uniqueness, schedule overlap locks, and the database
`schedule_key` make replay idempotent. Do not run both scheduler approaches.

`GET /api/v1/health` returns HTTP 503 when an H4 boundary is missed, a provider
is stale, or a failed job occurred in the last 24 hours. Watch
`storage\logs\operations.json.log`, queue depth, failed jobs, disk space, and
that endpoint. The endpoint reports no secrets or provider error text.

## Provider/API setup

The default `MARKET_DATA_PROVIDER=mock` is explicit fixture data. For read-only
Twelve Data set `MARKET_DATA_PROVIDER=twelve-data` and
`TWELVE_DATA_API_KEY=<secret>` in `.env`. The adapter permits only XAU/USD
quotes/candles, uses bounded connect/request timeouts and retries, persists
health, and throws on failure. It never substitutes fixture or invented prices.
After changing `.env`, run `php artisan config:clear` (development) or rebuild
the production config cache.

Rate limits are controlled by `RATE_LIMIT_*_PER_MINUTE`. API POSTs use Laravel
CSRF protection because routes are intentionally in the web middleware group.
Browser clients should send the normal XSRF token. Paper lifecycle mutations
also require a unique `Idempotency-Key` header.

## Backup and restore

Create an encrypted/off-host copy after the script completes:

```powershell
.\scripts\backup-xampp.ps1 -RetentionDays 14
.\scripts\restore-xampp.ps1 -Backup .\storage\backups\diamond-YYYYMMDD-HHMMSS.zip
```

Scripts prompt securely and never embed credentials. Local ZIPs are excluded
from git but are not encrypted; restrict ACLs, copy them to encrypted storage,
and test restoration quarterly. Suggested retention is 14 daily, 8 weekly, and
12 monthly off-host backups. Stop queue workers for a point-in-time restore,
restore into a new database first, verify row counts and `migrate:status`, then
switch configuration. Keep the application key backed up separately: encrypted
sessions cannot be recovered without it.

## Paper-production readiness checklist

- [ ] Apache serves only `public`; HTTPS and certificate renewal are verified.
- [ ] `APP_DEBUG=false`; secrets exist only in `.env` with restricted ACLs.
- [ ] Paper-only boot guards and database migration checks pass.
- [ ] Authentication/ownership is implemented before enabling production
      mutation APIs; until then a 403 is the expected result.
- [ ] Queue and exactly one scheduler are supervised and restart automatically.
- [ ] `/api/v1/health`, JSON logs, failed jobs, disk, DB, and backup age alert.
- [ ] Provider clock/timezone is UTC; stale/future quote tests pass.
- [ ] Rate limits match provider quota and expected local traffic.
- [ ] Backup restore drill succeeded and off-host retention is documented.
- [ ] No broker SDK, broker credential, live order route, or live execution
      capability exists.

## Troubleshooting

- **403 on POST in production:** expected until authenticated ownership exists.
- **419 CSRF:** load a Laravel/Inertia page first and send the XSRF header;
  do not disable CSRF.
- **429:** inspect rate-limit values and caller IP/proxy configuration; do not
  bypass limits.
- **503 health:** inspect `providers`, `missedH4Analysis`, failed jobs, scheduler,
  queue worker, system UTC clock, and JSON logs.
- **Provider stale/error:** validate API key/quota/TLS and outbound HTTPS. The
  application deliberately does not fall back to fabricated market data.
- **Backtest remains QUEUED:** start the `backtests` queue and verify
  `BACKTEST_PYTHON`, Python 3.12, and `PYTHONPATH` process permissions.
- **Apache 500:** check PHP extensions, writable `storage`/`bootstrap\cache`,
  `APP_KEY`, Apache error log, and Laravel JSON log.
- **Migration rollback fails:** ensure no dependent custom tables were added
  after these migrations and use a tested backup rather than forcing DDL.
