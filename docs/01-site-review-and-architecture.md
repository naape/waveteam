# WaveTeam Portal — Phase 1: Site Review & Architecture Proposal

Status: **Approved in part.** `portal.waveteam.lk` was approved on 2026-11-01. Other open decisions are tracked in `04-phase-2-report.md`. Nothing on the live site (waveteam.lk) has been changed.
Date: 2026-11-01

---

## 1. Findings: the current WaveTeam website

These come from read-only HTTP requests to the public site. Nothing was logged into or changed.

| Area | Finding | Evidence |
|---|---|---|
| CMS | **WordPress 7.0**, installed in the `/web/` subfolder | `<meta name="generator" content="WordPress 7.0">`; `https://www.waveteam.lk` → 301 → `https://waveteam.lk/web/` |
| Theme | **Makaffo 1.1.12** by OceanThemes (a commercial ThemeForest theme) | `wp-content/themes/makaffo/style.css` |
| Page builder | Elementor 3.33.6 and Elementor Pro | generator meta, `wp-content/plugins/elementor*` |
| Plugins seen | Slider Revolution 6.7.39, Contact Form 7, CookieAdmin (+Pro) | asset paths |
| Web server | Apache, HTTP/2, HTTPS on | response headers |
| Hosting | **HostGator** (probably shared or cPanel hosting), IP 50.6.227.38 (US) | `NS ns1/ns2.50-6-227-38.www.hostgator.com`; A record |
| Mail | `mail.waveteam.lk` (cPanel mail on the same host) | MX record |
| Built by | "Designed by LakWeb Hosting Solutions" | footer |
| Fonts | Epilogue (headings), Roboto, Roboto Slab | Google Fonts links |
| Logo | `wp-content/uploads/2024/02/waveteamlogo-e1766244349447.png` (311×263 PNG) | header |
| Brand colours (taken from the logo) | Navy blue (wordmark, "Wave"), teal (pulse line), lime green (lightning bolt, "team") | logo image. Elementor's global colours are still the factory defaults, so the logo is the only reliable brand source |
| Careers / jobs | **None.** The only form is a Contact Form 7 contact form at `/web/contacts/` | — |
| `portal.waveteam.lk` | Does not resolve yet | DNS |

### Issues on the live site (for your information, not part of this project)
- Much of the page copy is still **template demo text**: "largest biogas plant in Europe… 26 MW", "Lorem ipsum", ">1.5 m tons of pipes supplied", and "Recycled Energy" blog posts from 2023.
- The "Get in touch" / "Let's work together" buttons link to the **theme vendor's demo site** (`demo.oceanthemes.site/makaffo/contacts/`), not to your own contact page.
- The phone link on the Contact page dials `+1 800 759-89-43`, but the page displays `+94 77 315-67-69`.
- The footer links for Privacy Policy, Terms and FAQ are `#` placeholders.

Before launch, someone should fix these in WordPress, especially the Privacy Policy, because the portal will link to it.

---

## 2. Integration decision

**Recommendation: host the portal on the subdomain `portal.waveteam.lk`, as a separate Laravel application.**

| Option | Verdict | Why |
|---|---|---|
| `portal.waveteam.lk` (subdomain) | ✅ **Recommended** | Fully isolated from WordPress: separate document root, cookies, sessions, `.htaccess` and PHP settings. A WordPress plugin or theme update cannot break it, and a WordPress compromise cannot read its session cookies. It can move to a different server later by changing one DNS record. |
| `waveteam.lk/portal` (sub-path) | ⚠️ Possible, not advised | It would share the domain's cookies and its root `.htaccess` with WordPress, which already rewrites `/` → `/web/`. The rewrite rules could interfere with each other, and a later move would be harder. |
| WordPress plugin | ❌ Rejected | This much sensitive data (NIC numbers, police clearance certificates) should not depend on WordPress's plugin ecosystem or the theme's update cycle. |

**The only change on the WordPress side is adding menu links** (e.g. "Careers / Apply" → `portal.waveteam.lk/applicants`, "Request a Service" → `portal.waveteam.lk/organizations`). This is done in *Appearance → Menus* after the portal is live. No theme or plugin code is touched.

---

## 3. Recommended technology stack

| Layer | Choice | Reason |
|---|---|---|
| Language / runtime | **PHP 8.3+** | HostGator and every Sri Lankan cPanel host support it. It is already installed locally (MAMP PHP 8.3.30 has sodium, intl, gd, zip and bcmath). |
| Framework | **Laravel 12** (LTS-style support, very large ecosystem) | Includes authentication, queues, scheduler, mail, notifications, file storage, encryption and policy-based authorisation. Many Sri Lankan developers know it, so the system is easier to maintain long term. |
| Database | **MySQL 8.0** (InnoDB, utf8mb4) | Relational, available on cPanel and MAMP, and handles Sinhala and Tamil text with utf8mb4. |
| Applicant & organisation portals | **Blade + Livewire 3 + Alpine.js + Tailwind CSS 4** | Pages are rendered on the server, so they are fast on mobile data and good for SEO. Forms are reactive (repeaters for qualifications and employment history) without building a separate SPA. |
| Admin portal | **Filament 4** (themed to the WaveTeam brand), inside the same Laravel app | Comes with tables, filters, bulk actions, forms, dashboards and widgets. Building the 18 admin modules from scratch would take months; Filament cuts that time roughly in half. |
| Authentication | Laravel Fortify. TOTP 2FA is **mandatory for staff** and optional for others. Email verification, password reset and throttling | — |
| RBAC | `spatie/laravel-permission`, plus Laravel Policies on every model | Permissions are fine-grained, e.g. `police_clearance.verify` is kept separate from `applicants.view`. |
| API (future mobile app) | Laravel **Sanctum** token API under `/api/v1`, with versioned JSON resources | The same policies apply to web and mobile. |
| Documents | Private disk **outside the web root**, files encrypted at rest (libsodium), served only through an authorised controller | Police clearance certificates can never be reached by URL. |
| Background jobs | Laravel Scheduler + database queue, driven by **one cPanel cron entry** | No Redis or Supervisor is needed on shared hosting. Switching to Redis on a VPS later is a config change. |
| Email | Laravel Notifications over SMTP. **Recommended: a transactional provider** (Amazon SES, Brevo or Postmark) on a `mail.waveteam.lk` sending domain with SPF, DKIM and DMARC | Shared-host SMTP is often rate-limited or lands in spam, and reminder emails must arrive. |
| SMS / WhatsApp (later) | Custom Notification channels (e.g. Dialog / Mobitel SMS API, WhatsApp Business Cloud API) | The channel interface is designed in now; only the adapters get added later. |
| Reports | `maatwebsite/excel` (XLSX/CSV) and `barryvdh/laravel-dompdf` (PDF) | — |
| Backups | `spatie/laravel-backup`: nightly encrypted DB and documents backup to off-server storage (e.g. Backblaze B2 or S3), 30-day retention | — |
| Monitoring | Laravel log + Sentry (free tier) + uptime monitor | — |
| i18n | Every string goes through `__()` with `lang/en`, and `lang/si` and `lang/ta` are prepared. Fonts: Inter + Noto Sans Sinhala / Noto Sans Tamil | A Sinhala/Tamil switch is added later with no code changes. |
| Testing | Pest (unit and feature), Laravel Dusk for critical flows | — |

### Hosting recommendation
The portal will hold NIC numbers and police certificates. **Production should run on a small VPS** (2 vCPU / 4 GB, e.g. DigitalOcean or AWS Lightsail in Singapore, or a Sri Lankan provider), not shared hosting. A VPS gives:
- isolation from other tenants on a shared server,
- a real queue worker, Redis and a firewall,
- full control of PHP, backups and TLS.

**The app will also run on HostGator cPanel** if a VPS is not possible. It needs PHP 8.3, SSH access, cron, about 10 GB of storage, and a subdomain whose document root points to `portal/public`. Tell me which plan you have. The decision only affects Phase 10 (deployment).

> Data residency: HostGator's IP is in the US. Sri Lanka's **Personal Data Protection Act No. 9 of 2022 (PDPA)** places conditions on transferring personal data outside the country. Please get legal advice on the hosting location. A Singapore or Sri Lankan host makes this simpler.

### Local development
The local setup already has MAMP with PHP 8.3/8.4, MySQL 8 and Composer. **Node.js is not installed.** It is needed only on the development machine to build CSS/JS (`brew install node`). The production server does not need Node.

---

## 4. Application architecture

```
                       ┌─────────────────────────────────────────────┐
  www.waveteam.lk ───► │ WordPress (unchanged) — menu links to portal│
                       └─────────────────────────────────────────────┘
                                         │
                                         ▼
  portal.waveteam.lk  ┌───────────────────────────────────────────────────────┐
  (HTTPS only, HSTS)  │                  Laravel 12 application                │
                      │                                                       │
  /                   │  Public home  — two portal cards + admin link         │
  /applicants/*       │  Applicant portal   (Blade/Livewire, guard: web)      │
  /organizations/*    │  Organisation portal(Blade/Livewire, guard: web)      │
  /admin/*            │  Admin portal       (Filament, staff only + 2FA)      │
  /api/v1/*           │  REST API           (Sanctum, future mobile)          │
  /files/{uuid}       │  Authorised document stream (policy-checked, logged)  │
                      │                                                       │
                      │  Domain modules (app/Domain/*):                        │
                      │   Identity · Applicants · Documents · PoliceClearance │
                      │   Recruitment · Organizations · ServiceRequests       │
                      │   Quotations · Workforce · Notifications · Reporting  │
                      │   Audit · Settings                                    │
                      └───────────┬───────────────┬───────────────┬───────────┘
                                  │               │               │
                          MySQL 8 (InnoDB)   Private storage   Scheduler + Queue
                                             (encrypted files)  (cron every minute)
                                                                  │
                                                     SMTP / SES ◄─┘  (SMS/WhatsApp later)
```

### Portal separation
- There is one `users` table with a `user_type` column: `applicant`, `org_contact` or `staff`. Each portal has its **own landing page, registration and login** (`/applicants/login`, `/organizations/login`, `/admin/login`).
- Middleware `EnsureUserType` blocks access to the wrong portal. An applicant's credentials **cannot** log in on the organisation or admin pages, and the reverse is also true.
- Staff accounts can only be created by a Super Admin. Self-registration never creates staff accounts. 2FA is enforced before any admin page loads.
- An optional IP allowlist can be applied to `/admin`.

### Code organisation (no single-file app)
```
app/
  Domain/<Module>/{Models,Actions,Enums,Events,Listeners,Policies,Notifications,Services}
  Http/{Controllers,Middleware,Requests}        # applicant & org portals
  Livewire/{Applicant,Organization}/...
  Filament/Resources/...                         # admin
  Console/Commands/                               # scheduled jobs
database/{migrations,seeders,factories}
resources/views/{layouts,applicant,organization,public,emails}
lang/{en,si,ta}
tests/{Unit,Feature,Browser}
```
Business rules live in **Action classes** (e.g. `VerifyPoliceClearance`, `AssignApplicantToRequest`). The web portals, admin and API all call the same Action classes, so a rule cannot be skipped by going through a different interface.

---

## 5. Security design (summary)

| Requirement | Implementation |
|---|---|
| Password hashing | Argon2id (Laravel `hash.driver=argon2id`). Minimum 10 characters, checked against breached-password lists (`Password::uncompromised()`) |
| MFA | TOTP (Google/Microsoft Authenticator) plus recovery codes. **Mandatory for all staff** |
| Sessions | Database sessions, `Secure` / `HttpOnly` / `SameSite=Lax` cookies, 30-min idle timeout for staff and 120-min for others, regenerated on login, "log out other devices" |
| Brute force | Throttles on login, password reset and 2FA. Lockout plus an alert email after repeated failures |
| SQLi | Eloquent and query-builder bindings only. Raw SQL is banned by a code-review rule |
| XSS | Blade auto-escaping, no `{!! !!}` on user input, strict **Content-Security-Policy**, sanitised rich text (HTMLPurifier) |
| CSRF | Laravel CSRF tokens on every form |
| Headers | HSTS, X-Frame-Options DENY, X-Content-Type-Options, Referrer-Policy, Permissions-Policy |
| RBAC | Roles + permissions + per-model Policies. Every admin action is authorised on the server |
| Sensitive fields | NIC and passport numbers are **encrypted at the column level**. A keyed hash (blind index) makes exact-match search possible without decryption |
| Documents | Stored outside the web root with random UUID filenames and **encrypted at rest**. Type is validated by magic bytes, not the file extension (PDF/JPG/PNG only, max 5 MB, configurable). Optional ClamAV scan where the host provides it. Downloads are streamed through a policy check and **every view or download is audit-logged** |
| Police clearance certificates | Need a separate permission, `police_clearance.view`, which plain recruiters do not get by default. Never cached and never publicly reachable |
| Audit log | Append-only `audit_logs` table: actor, action, entity, before/after (sensitive values redacted), IP, user agent |
| Account control | Deactivate or reactivate users. Deactivated users are logged out immediately and API tokens are revoked |
| Backups | Nightly encrypted off-site backups, with a restore test every quarter |
| Privacy (PDPA 2022) | Explicit consent checkbox at registration, stored with the policy version. Privacy notice, retention policy, "download my data" and "request deletion" (handled by an admin, because of legal retention needs) |

---

## 6. Decisions needed from you before Phase 2

1. **Subdomain**: is `portal.waveteam.lk` approved?
2. **Hosting**: VPS (recommended) or the existing HostGator plan? If HostGator, which plan (shared, VPS or dedicated), and do you have SSH access?
3. **Email provider**: transactional service (recommended) or the cPanel mailbox?
4. **Brand assets**: do you have the logo as SVG or high-resolution PNG, and the exact brand colour codes? Until then I'll use navy / teal / green taken from the current logo.
5. **Admin roles**: is the proposed role list in `02-database-design.md §9` correct?
6. **Police clearance rules**: is 12 months from issue date the default validity, with an admin override per certificate?
7. **Legal**: who will review the privacy policy and retention periods (PDPA 2022)? I will draft them, but a Sri Lankan lawyer should approve them.
