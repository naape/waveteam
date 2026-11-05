# Deploying the WaveTeam Portal to https://www.waveteam.lk/app

The portal is a Laravel 12 application. It runs in the `/app` folder of the main website, **next to WordPress**:

| Address | Served by |
|---|---|
| `https://www.waveteam.lk/` | WordPress (unchanged) |
| `https://www.waveteam.lk/app` | Portal home |
| `https://www.waveteam.lk/app/applicants` | Applicant portal |
| `https://www.waveteam.lk/app/organizations` | Organisation portal |
| `https://www.waveteam.lk/app/admin` | Admin panel |

The code lives **outside** `public_html`. Only its `public` folder is exposed, through a link named `app`. WordPress files are not touched.

---

## 1. Server requirements (HostGator cPanel)
- PHP **8.3+** (cPanel → *Select PHP Version* / *MultiPHP Manager*) with these extensions: `sodium`, `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `fileinfo`, `openssl`, `curl`
- MySQL **5.7+ / 8.0** or MariaDB **10.3+**
- **SSH or cPanel → Terminal**, needed for `composer` and `php artisan`
- SSL for `www.waveteam.lk` (AutoSSL) is already in place for WordPress

## 2. Get the code onto the server (cPanel → Git Version Control)
1. Clone the GitHub repository into a folder **outside** `public_html`, e.g. `/home/<cpanel-user>/waveteam`.
   *(A private repository needs a deploy key: cPanel → SSH Access → generate a key, then add the public key in GitHub → repository → Settings → Deploy keys.)*
2. In **Terminal**, install the PHP libraries (they are not in git):
   ```bash
   cd ~/waveteam
   composer install --no-dev --optimize-autoloader
   ```
   If `composer` is not found, try `php ~/composer.phar ...`, or ask HostGator support to enable it.
   The compiled CSS/JS (`public/build`) is already in git, so Node is **not** needed on the server.

## 3. Create the database (cPanel → MySQL® Databases)
1. **Create New Database**, e.g. `waveteam_portal`. cPanel adds your account prefix, giving e.g. `abcd_waveteam_portal`.
2. **Add New User** with a strong generated password.
3. **Add User To Database** → tick **ALL PRIVILEGES**.

Note the full names (with the prefix) for `.env`.

**You do not upload a database.** The installer in step 5 creates every table and loads the reference data: districts, job categories, skills, roles and permissions, email templates and policies. See §9 if you really need to copy your local data.

## 4. Create `.env`
```bash
cd ~/waveteam
cp deploy/env.production.example .env
nano .env
```
- `APP_URL=https://www.waveteam.lk/app` (already set)
- `DB_HOST=localhost`, plus `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` from step 3
- `DOCUMENTS_ROOT=/home/<cpanel-user>/waveteam-documents`, outside `public_html`
- `MAIL_*`: your email provider (see §8)
- Leave `APP_KEY`, `BLIND_INDEX_KEY` and `DOCUMENT_ENCRYPTION_KEY` **empty**; the installer generates them.

## 5. Install
```bash
cd ~/waveteam
php artisan portal:install --force
```
The installer:
- checks the server
- **generates the three encryption keys** into `.env`
- creates the tables
- loads the reference data, roles, email templates and policies
- creates the **first Super Admin** and prints its password **once**; copy it
- creates the private documents folder
- builds the production caches

Then **back up `.env`** to a password manager. Without `APP_KEY`, `BLIND_INDEX_KEY` and `DOCUMENT_ENCRYPTION_KEY`, NIC numbers and every uploaded document are permanently unreadable.

> On this server PHP's command-line binary may be `php` (older) while the website uses 8.3. Check with `php -v`. If needed use the full path, e.g. `/opt/cpanel/ea-php83/root/usr/bin/php artisan ...`.

## 6. Publish it at /app
```bash
ln -s ~/waveteam/public ~/public_html/app
```
That's all. WordPress's `.htaccess` already skips real folders, and the portal's own `public/.htaccess` takes over inside `/app`.

Check:
- `https://www.waveteam.lk/app`: portal home
- `https://www.waveteam.lk/app/admin`: admin sign-in
- `https://www.waveteam.lk/app/.env`: must be **404**
- `https://www.waveteam.lk/`: WordPress as before

> **If a WordPress caching or security plugin** (WP Rocket, LiteSpeed Cache, W3TC, Wordfence…) is active, exclude `/app/*` from caching and from its firewall rules. Otherwise users may see each other's cached pages.

## 7. Scheduled jobs (cPanel → Cron Jobs, "Once Per Minute")
```
* * * * * cd /home/<cpanel-user>/waveteam && php artisan schedule:run >> /dev/null 2>&1
```
This runs:
- the police clearance reminders (06:00)
- the weekly Operations digest
- the vacancy and quotation expiry jobs
- the email queue

Check it in **Admin → Administration → Email servers (SMTP) → Email queue**: "Automatic sending" should say **Running**. Most portal emails wait in the queue until this job runs. Failed emails can be retried on the same page.

## 8. Email (SPF, DKIM, DMARC)
Use a transactional provider (Brevo, Amazon SES, Postmark), or HostGator's SMTP for low volume.
- Enter its SMTP details in **Admin → Administration → Email servers (SMTP)**, click **Send test email**, then **Use this server**. The settings in `.env` (`MAIL_*`) are only a fallback, used while no server is active.
- Add the provider's **SPF** and **DKIM** records for `waveteam.lk`.
- Add a DMARC record: `_dmarc.waveteam.lk TXT "v=DMARC1; p=quarantine; rua=mailto:info@waveteam.lk"`

To test, register an applicant and confirm the verification email arrives in the inbox, not spam.

## 9. (Optional) Copying your local database instead
Only do this if you need data you entered locally. Demo accounts and test data would come along too.

1. **Export** on the Mac:
   ```bash
   /Applications/MAMP/Library/bin/mysql80/bin/mysqldump -h 127.0.0.1 -P 8889 -u root -proot \
     --single-transaction --no-tablespaces waveteam_portal > ~/Desktop/waveteam_portal.sql
   ```
2. **Import:** cPanel → phpMyAdmin → select the empty database from step 3 → **Import** → choose the file. If the server is MariaDB or MySQL 5.7 and the import complains about `utf8mb4_0900_ai_ci`, replace that text with `utf8mb4_unicode_ci` in the file and import again.
3. **Copy the three keys** (`APP_KEY`, `BLIND_INDEX_KEY`, `DOCUMENT_ENCRYPTION_KEY`) from your **local** `.env` into the server `.env` **before** running anything. Without them the imported NIC numbers and documents cannot be read.
4. **Copy the documents:** upload the contents of the local `storage/app/documents` folder to the server's `DOCUMENTS_ROOT`.
5. Run `php artisan portal:install --force`. It keeps the existing keys and the existing admin.
6. Change the admin and demo passwords, or deactivate the demo accounts.

## 10. Go-live checklist
- [ ] `.env`: `APP_DEBUG=false`, and `EMAIL_VERIFICATION_REQUIRED=true` and `ADMIN_MFA_REQUIRED=true` (both **on**)
- [ ] Sign in at `/app/admin` with the printed password, set up the authenticator app, and store the recovery codes.
- [ ] Admin → Staff: invite real staff and give them roles.
- [ ] Admin → System settings: confirm the police clearance validity and reminder days.
- [ ] Admin → Email templates: review the wording.
- [ ] Replace the **draft** privacy, terms and retention policies once the lawyer has approved them.
- [ ] Back up the database and `DOCUMENTS_ROOT` nightly, off-server, and **test a restore**.
- [ ] **WordPress** (Appearance → Menus): add "Careers" → `https://www.waveteam.lk/app/applicants` and "Request a Service" → `https://www.waveteam.lk/app/organizations`.

## Updating to a new version
1. Push to GitHub.
2. In cPanel → Git Version Control → **Update from Remote** (or `git pull` in `~/waveteam`).
3. Then:
   ```bash
   cd ~/waveteam
   php artisan down
   composer install --no-dev --optimize-autoloader
   php artisan portal:install --force     # migrations + caches; keys and admin untouched
   php artisan up
   ```

> Use `php artisan portal:optimize` (not `php artisan optimize`) to rebuild caches. In a sub-folder, Laravel's route cache breaks the `/app/` home page, so this command skips it.

## Security note: sharing the domain with WordPress
At `/app` the portal shares an origin with WordPress. A vulnerable WordPress plugin could therefore be used to act inside the portal for a signed-in admin. The portal limits this:
- its cookies are scoped to `/app` and are HttpOnly
- it uses its own Content-Security-Policy
- MFA is on

Still, **keep WordPress and its plugins updated** and remove unused plugins. Moving to `portal.waveteam.lk` later needs only `APP_URL` and the symlink changed; no code changes.

## Not done yet (planned hardening, Phase 10)
- Nonce-based Content-Security-Policy (currently allows inline scripts for Livewire)
- Dependency and OWASP review, penetration test, upload-abuse tests
- Load test with ~10k applicants
- Off-site encrypted backup automation
