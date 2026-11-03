# WaveTeam Recruitment & Service Management Portal

Separate Laravel application, served at **https://www.waveteam.lk/app** (next to the WordPress site; see docs/DEPLOYMENT.md). It provides:
- an Applicant portal
- a Service Request portal for organisations
- an Administrator portal for WaveTeam staff

Docs: [`docs/`](docs). Start with `01-site-review-and-architecture.md`, then read the phase reports.

## Local deployment (MAMP, production-like)
The portal is served by MAMP's Apache at **http://localhost:8890/app** (admin: `/app/admin`), laid out like the live site: a WordPress stand-in at the root, the portal in `/app`. To set it up, or to re-apply the set-up after a MAMP update, run:

```bash
./deploy/local-mamp.sh
```

The script:
- enables `mod_rewrite`
- adds `/Applications/MAMP/conf/apache/extra/waveteam-portal.conf` (site on port 8890)
- **blocks the project folder on the default MAMP site**, which would otherwise expose `.env`
- fixes the macOS php-cgi crash on outbound HTTPS (`OBJC_DISABLE_INITIALIZE_FORK_SAFETY`)
- builds the assets, caches the config and restarts Apache

A backup of MAMP's original config is kept at `/Applications/MAMP/conf/apache/httpd.conf.waveteam-backup`.

- Emails are written to `storage/logs/mail.log`, which is where you'll find the verification links.
- **Before running tests, run `php artisan config:clear`**, then `php artisan portal:optimize` afterwards. The tests refuse to run against the real database while the config is cached.
- The scheduler (police clearance reminders and other jobs) runs only if you add this cron line yourself (`crontab -e`):
  `* * * * * cd /Applications/MAMP/htdocs/waveteam && /Applications/MAMP/bin/php/php8.3.30/bin/php artisan schedule:run >> /dev/null 2>&1`

## Deploying
To deploy, follow **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**:
- `./deploy/build-release.sh` builds `dist/waveteam-portal-<date>.zip`
- on the server, copy `deploy/env.production.example` to `.env`, then run `php artisan portal:install --force`

## Stack
PHP 8.3, Laravel 12, MySQL 8, Filament 4 (admin), Livewire 3, Tailwind 4, spatie/laravel-permission, Fortify, Sanctum.

## Local development (macOS + MAMP)

```bash
export PATH=/Applications/MAMP/bin/php/php8.3.30/bin:$PATH
composer install            # or: php /Applications/MAMP/bin/php/composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # paste into BLIND_INDEX_KEY
# .env: DB_PORT=8889, DB_PASSWORD=root (MAMP defaults), DB_DATABASE=waveteam_portal
php artisan migrate --seed  # prints the initial Super Admin password ONCE
php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"   # paste into DOCUMENT_ENCRYPTION_KEY
php artisan db:seed --class=DemoVacancySeeder        # optional demo data (never in production)
php artisan db:seed --class=DemoOrganizationSeeder   # demo client: kumari@ltc.lk / SecurePass123
php artisan serve           # http://127.0.0.1:8000  (admin: /admin)
```

During front-end work, run `npm run dev` to get hot reload.

MAMP's Apache ships with `mod_rewrite` disabled. That's why `php artisan serve` is used locally. To use MAMP's Apache instead, enable `LoadModule rewrite_module` in `/Applications/MAMP/conf/apache/httpd.conf` and point a vhost at `public/`.

**First admin login:** you will be asked to scan a QR code with an authenticator app (Google or Microsoft Authenticator). Keep the recovery codes safe.

## Scheduled jobs
Production needs one cron entry. It runs the police clearance reminders, the weekly Operations digest, housekeeping jobs and the queue worker:

```
* * * * * cd /path/to/portal && php artisan schedule:run >> /dev/null 2>&1
```

To list the jobs, run `php artisan schedule:list`. To send due police clearance reminders by hand (safe to re-run), run `php artisan pcc:send-reminders`.

## Tests
Tests run against the MySQL database `waveteam_portal_test`:

```bash
php artisan test
```

## Security notes
- **Never commit `.env`**. `APP_KEY`, `BLIND_INDEX_KEY` and `DOCUMENT_ENCRYPTION_KEY` must be backed up securely, separately from database backups. If they are lost, encrypted NIC numbers and uploaded documents cannot be read.
- After editing Blade views, run `npm run build` (or keep `npm run dev` running) so Tailwind generates the new classes.
- Uploaded documents live in `storage/app/documents` (private). In production, set `DOCUMENTS_ROOT` to a path outside the web root and include it in backups.
