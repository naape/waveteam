# Deploying the WaveTeam Portal to portal.waveteam.lk

The portal is a standard Laravel 12 application. It does not touch the WordPress site; WordPress only gets two menu links at the end.

You can deploy to **Option A**, a small VPS (recommended), or **Option B**, the existing HostGator cPanel account. Steps 1 and 6–9 are the same for both.

---

## 1. Build the release (on the development Mac)
```bash
cd /Applications/MAMP/htdocs/waveteam
./deploy/build-release.sh
```
This produces `dist/waveteam-portal-<date>.zip`, which contains:
- production PHP dependencies (no dev tools)
- compiled CSS/JS
- the application code

It does **not** contain `.env`, uploaded documents, logs, tests or `node_modules`.

## 2. Server requirements
- PHP **8.3+** with these extensions: `sodium`, `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `fileinfo`, `openssl`, `curl`
- MySQL **8.0**, or MariaDB 10.6+
- HTTPS for `portal.waveteam.lk`
- SSH access, or cPanel Terminal, to run `php artisan` commands
- About **10 GB** of disk for documents, growing with applicants

---

## Option A — VPS (Ubuntu 24.04, Nginx, PHP-FPM)

**1. Point DNS.** Create an **A record** `portal.waveteam.lk → <server IP>`.

**2. Install the server packages:**
```bash
sudo apt update && sudo apt install -y nginx mysql-server unzip \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-intl php8.3-gd php8.3-zip php8.3-xml php8.3-curl php8.3-bcmath
sudo mysql -e "CREATE DATABASE waveteam_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'waveteam'@'localhost' IDENTIFIED BY '<strong password>';
  GRANT ALL ON waveteam_portal.* TO 'waveteam'@'localhost';"
```

**3. Upload and unpack the release:**
```bash
sudo mkdir -p /var/www/portal /var/lib/waveteam-documents
sudo unzip waveteam-portal-*.zip -d /var/www/portal
sudo chown -R www-data:www-data /var/www/portal/storage /var/www/portal/bootstrap/cache /var/lib/waveteam-documents
sudo chmod 700 /var/lib/waveteam-documents
```

**4. Configure Nginx.** The site root **must be the `public` folder**:
```nginx
server {
    listen 80;
    server_name portal.waveteam.lk;
    root /var/www/portal/public;
    index index.php;
    client_max_body_size 25M;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

**5. Turn on HTTPS:**
```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d portal.waveteam.lk
```

Then continue with **section 6**.

---

## Option B — HostGator cPanel

**1. Create the subdomain.** In cPanel → **Domains**, create `portal.waveteam.lk` and set its **document root** to `portal/public`. The document root must be `public`, never the app folder itself.

**2. Create the database.** In cPanel → **MySQL Databases**, create a database and a user, and grant the user ALL privileges on that database.

**3. Upload the code.** In cPanel → **File Manager**, upload the zip to your home directory and **Extract** it into `~/portal`. The app code then sits **outside** `public_html`.

**4. Create the documents folder.** Make `~/portal-documents` (outside `public_html`) and set it to permission 700.

**5. Set PHP up.** In cPanel → **Select PHP Version**, choose 8.3 and enable the extensions listed in section 2.

**6. Turn on SSL.** In cPanel → **SSL/TLS Status**, run AutoSSL for `portal.waveteam.lk`.

Then continue with **section 6**, using cPanel → **Terminal** (or SSH) for the commands.

---

## 6. Configure and install (both options)
```bash
cd /var/www/portal            # or ~/portal on cPanel
cp deploy/env.production.example .env
nano .env                     # fill DB_*, MAIL_*, DOCUMENTS_ROOT; leave the 3 keys empty
php artisan portal:install --force
```

The installer:
- checks the server requirements
- **generates the three encryption keys**
- runs the migrations
- loads districts, categories, skills, roles, email templates and the draft policies
- creates the **first Super Admin** and prints its password **once**
- caches the configuration for speed

It is safe to re-run after every update.

> **Back up `.env` immediately.** Store it in a password manager, separately from database backups. The keys `APP_KEY`, `BLIND_INDEX_KEY` and `DOCUMENT_ENCRYPTION_KEY` cannot be recovered. Without them, NIC numbers and every uploaded document are permanently unreadable.

## 7. Scheduled jobs (one cron line)
On a VPS, run `sudo crontab -u www-data -e`. On cPanel, use **Cron Jobs** with "Once per minute". Then add:
```
* * * * * cd /var/www/portal && php artisan schedule:run >> /dev/null 2>&1
```

This runs:
- the police clearance reminders (06:00)
- the weekly Operations digest
- the vacancy and quotation expiry jobs
- the email queue (every minute)

To check it: `php artisan schedule:list`

## 8. Email (SPF, DKIM, DMARC)
Use a transactional provider: Amazon SES, Brevo or Postmark.
- Put its SMTP details in `.env`.
- Add the provider's **SPF** and **DKIM** DNS records for `waveteam.lk`.
- Add a DMARC record: `_dmarc.waveteam.lk TXT "v=DMARC1; p=quarantine; rua=mailto:info@waveteam.lk"`

To test, register an applicant and confirm the verification email arrives in the inbox, not spam.

## 9. First sign-in & go-live checklist
- [ ] Open `https://portal.waveteam.lk/admin`, sign in with the printed password, and set up the authenticator app. Store the recovery codes safely.
- [ ] Admin → Staff: invite real staff and give them roles.
- [ ] Admin → System settings: confirm the police clearance validity and reminder days.
- [ ] Admin → Email templates: review the wording.
- [ ] Replace the **draft** privacy, terms and retention policies once the lawyer has approved them. This means publishing a new version.
- [ ] Admin → Vacancies: publish the real vacancies.
- [ ] Set up a backup of the database and `DOCUMENTS_ROOT`, nightly and off-server, and **test a restore**.
- [ ] **WordPress** (Appearance → Menus): add "Careers" → `https://portal.waveteam.lk/applicants` and "Request a Service" → `https://portal.waveteam.lk/organizations`.

## Updating to a new release
```bash
php artisan down
# unzip the new release over the old one (keep .env, storage/ and the documents folder)
php artisan portal:install --force
php artisan up
```

## Not done yet (planned hardening, Phase 10)
- Nonce-based Content-Security-Policy (currently allows inline scripts for Livewire)
- Dependency and OWASP review, penetration test, upload-abuse tests
- Load test with ~10k applicants
- Off-site encrypted backup automation (`spatie/laravel-backup`)
