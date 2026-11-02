# Phase 2 Report — Foundation & Database

Status: **Complete (local development).** Nothing has been deployed, and the live waveteam.lk site is untouched.
Date: 2026-11-01

## Decisions recorded
| # | Decision | Status |
|---|---|---|
| 1 | Portal URL: **portal.waveteam.lk** | ✅ Approved by WaveTeam |
| 2 | Hosting (VPS vs HostGator) | ⏳ Open. Only affects Phase 10 |
| 3 | Email provider | ⏳ Open. Local dev logs emails to `storage/logs` |
| 4 | Brand assets | Colours sampled from the current logo: navy `#0F58A2`, teal `#5CC5C8`, green `#7BC443`. An SVG logo is still wanted |
| 5 | Staff roles | Seeded as proposed. Changes are a seeder edit |
| 6 | Police clearance validity | Default 12 months and reminders at 30/14/7 days. All are admin settings |
| 7 | Legal review of the privacy and retention policy | ⏳ Open. Drafts come in Phase 3 |

## What was built
- **Laravel 12.70** app with Filament 4.16, Livewire 3, spatie/laravel-permission, Fortify and Sanctum, on PHP 8.3 and MySQL 8.
- **Database**: 49 tables, as in `02-database-design.md`. Includes versioned documents, police clearance records with a reminder ledger (unique key, so no duplicate emails), status-history tables, and an append-only audit log.
- **Models and enums**: 38 models and 19 status enums, each with a label and a colour.
  - The police clearance display status (Valid, Expiring Soon, Expired, Pending, Rejected) is **calculated** when shown, never stored.
  - A certificate is valid **only after admin approval**. A renewal date set by an admin overrides the expiry date.
  - Verification and status fields are **guarded**, so they cannot be set by form input. In development, an attempt is a hard error.
- **Sensitive data**:
  - NIC, passport, certificate and licence numbers are encrypted in the database.
  - NIC numbers have an HMAC blind index, so they can be found by exact match and duplicates are rejected.
  - Masked NIC display, e.g. `••••••••5678`.
  - Audit logger that redacts sensitive values and cannot be edited or deleted.
- **Documents disk** at `storage/app/documents`: private and outside the web root. The `serve` option is disabled on every private disk.
- **Security baseline**:
  - Argon2id password hashing, strict password rules in production.
  - Security headers (CSP, X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy, HSTS over HTTPS). The `X-Powered-By` header is removed.
  - Deactivated accounts are logged out on their next request.
  - Optional admin IP allowlist. Proxies are trusted only if listed explicitly.
  - Strict Eloquent mode in development.
- **Admin portal** at `/admin`, staff only:
  - **Two-factor sign-in with an authenticator app (TOTP) is mandatory**, with recovery codes. Staff without it are sent to set-up before any admin page loads.
  - WaveTeam colours and logo. Self-hosted Inter and Noto Sinhala/Tamil fonts, with no third-party font requests.
- **Public home page** with the two portal cards. Responsive (checked at 1440px and 390px). Self-hosted fonts that already support Sinhala and Tamil.
- **Seed data**:
  - the 25 districts in English, Sinhala and Tamil (the Sinhala and Tamil names need a native speaker's check)
  - the 10 service categories
  - 11 job categories with 55 skills
  - 7 roles and 31 permissions
  - 13 email templates
  - the default settings
  - one Super Admin, with a random password printed once
- **Tests**: 24 automated tests, all passing. They cover:
  - portal separation
  - mandatory MFA
  - logout of deactivated staff
  - Recruiters cannot open police certificates
  - an applicant given an admin role still cannot get in
  - every police clearance status
  - NIC encryption and blind index
  - audit log redaction and immutability
  - seeders can be re-run safely

## Problems found and fixed during the build
- Settings defaults were never read, because the dots inside keys such as `pcc.expiring_soon_days` were treated as nesting by `config()`. Found by the tests and fixed.
- Laravel's middleware priority ran `Authenticate` before the deactivated-account check, so deactivated staff were refused but not logged out. Fixed by placing the check ahead of authentication in the priority list.
- Filament's auto-generated palette made the buttons pale blue instead of WaveTeam navy. Fixed with explicit colour scales.

## Known items carried forward
- **The CSP still allows `'unsafe-inline'` and `'unsafe-eval'`**, because Livewire and Alpine need them. Moving to a nonce-based CSP is a Phase 10 task.
- Fortify's `POST /login` endpoint is active but not linked from any page. Phase 3 replaces it with the portal-specific login, registration and verification flows.
- The portal buttons on the home page (`/applicants/register` etc.) return 404 until Phase 3.
- MAMP's Apache has `mod_rewrite` disabled, so local development uses `php artisan serve` (see README).

## Next: Phase 3 (Authentication & registration)
- Separate login and registration for applicants and organisations.
- Email verification and password reset.
- Consent capture, with draft privacy and retention policies.
- Login audit trail and lockout.
- Staff account management in the admin portal.
