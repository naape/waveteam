# WaveTeam Portal — Implementation Plan

Each phase ends with a **working, demonstrable build** running locally (MAMP, at `http://localhost:8888/waveteam/public` or a `portal.test` vhost). Each one comes with a short report covering what was completed, how to test it and what is next. Automated tests are written alongside each phase, not left to the end.

The live WordPress site is **not touched** at any point. The only WordPress change is adding menu links after go-live (Phase 10), with your approval.

---

### Phase 1 — Site review & architecture ✅
Outputs: `01-site-review-and-architecture.md`, `02-database-design.md`, this plan.

### Phase 2 — Project foundation & database ✅ (see 04-phase-2-report.md)
- Laravel 12 project with Composer and Tailwind 4. Install Node locally (`brew install node`).
- All migrations from `02-database-design.md`, plus models, enums, factories and seeders (districts, service categories, job categories, starter skills, roles and permissions, default settings, one Super Admin).
- Brand design system: Tailwind theme tokens (navy / teal / green from the logo), Inter + Noto Sinhala/Tamil, base layouts for the public site, the applicant and organisation portals, and the Filament admin theme.
- Security baseline: security-headers middleware, CSP, Argon2id, encrypted casts, blind-index helper, private disk.
- **Demo:** `php artisan migrate --seed` builds the full schema. Home page shows the two portal cards. Basic admin login.

### Phase 3 — Authentication & registration ✅ (see 05-phase-3-report.md)
- Separate landing, registration and login pages for applicants, organisations and admins. Middleware stops users entering the wrong portal.
- Email verification, password reset, throttling, lockout, and "log out other sessions".
- Admin TOTP 2FA, mandatory and enforced, with recovery codes.
- Consent capture at registration, plus drafts of the privacy policy and retention policy pages (versioned).
- Account deactivation and reactivation. Audit logging of every login, failed login and security event.
- Emails: registration confirmation, account verification.
- **Tests:** a user cannot enter another portal; staff cannot reach the admin area without 2FA; throttling works.

### Phase 4 — Applicant portal ✅ (see 06-phase-4-report.md)
- Profile wizard: personal details → education → qualifications, skills and experience → employment → languages, driving licence and military service → preferences and availability.
- Profile completion percentage. Dashboard with all the widgets in the brief.
- Document centre: upload with type/size validation, versioning and history, secure viewing of the applicant's own files.
- Police clearance upload: issue date entered, status shown, history list (the verification itself comes in Phases 6–7).
- Vacancies: list, search by category and district, detail page, apply (CV snapshot), preferred locations, availability, application tracker.
- Interview and assignment notifications shown in the dashboard, plus account settings.

### Phase 5 — Service request portal ✅ (see 07-phase-5-report.md)
- Organisation registration (with supporting documents) and multiple contact users.
- Multi-step service request form covering every field in the brief, with attachments.
- Dashboard: requests and status timeline, quotations (view or download PDF, accept or reject with a comment), message thread with WaveTeam, assigned team (only if the admin allows it), completed history, document downloads.
- Emails: service request confirmation, quotation notifications.

### Phase 6 — Administrator dashboard ✅ (see 08-phase-6-report.md)
- Filament panel with the WaveTeam theme. Overview dashboard: KPIs, pending verifications, expiring certificates, open requests.
- Modules:
  - Applicants (search; filters for category, district, experience, verification and police clearance status; profile view; CV download; verify qualifications; change status)
  - Document verification queue
  - Vacancies, applications (kanban or status view, bulk actions), interviews (schedule and send invitation)
  - Organisations, service requests (status workflow, internal notes, messages), quotations (line items → branded PDF → send)
  - Staff and roles, permissions, audit-log viewer, system settings, notification template editor
- Every action is permission-checked and audit-logged.

### Phase 7 — Police clearance renewal & notification automation ✅ (see 09-phase-7-report.md)
- Compliance queue: view the certificate in a secure viewer. **Approve** (set or confirm `valid_until` and `next_renewal_due`) or **Reject** (with a reason that is emailed to the applicant).
- Admin is notified whenever a new certificate is uploaded (in-app alert and email to the Compliance Officer role).
- Daily scheduled job `pcc:send-reminders` (06:00 Asia/Colombo):
  - sends reminders at 30, 14 and 7 days before due (the days are configurable in Settings) and an expiry notice;
  - each reminder is sent once only, guaranteed by the unique key in the reminders table.
- Expired certificates automatically make the applicant ineligible for new assignments that require police clearance. Ops managers get a weekly digest of active assignments whose certificate expires during the project.
- Renewal settings screen. Full renewal history per applicant. Historical certificates are never auto-deleted.
- Notification channel abstraction (`mail` now, with `sms` and `whatsapp` adapters stubbed and documented).

### Phase 8 — Workforce matching & assignments ✅ (see 10-phase-8-report.md)
- "Find matches" from a service request. **Hard filters**: active and verified applicant, category, availability on the start date, valid police clearance up to the end date (when the request requires it).
- **Ranked score**, with weights adjustable in settings: skills overlap 40%, experience 25%, qualifications/certifications 15%, district or preferred-location fit 15%, profile completeness 5%. The UI explains why each person matched.
- Shortlist → assign (role, dates) → applicant notified → client sees the team (if allowed). Clash detection stops overlapping active assignments.
- Assignment lifecycle (proposed → confirmed → active → completed or released). Applicant availability updates automatically.

### Phase 9 — Reporting & analytics ✅ (see 11-phase-9-report.md)
- The 12 reports in the brief, each filterable by date, category and district, and exportable to **PDF and Excel**.
- Dashboard charts: applicants by category and district, police clearance validity breakdown, upcoming renewals (next 30/60/90 days), request pipeline, assignments over time.
- Large exports run as queued jobs, and the user is emailed a download link.

### Phase 10 — Hardening, UAT & deployment
- Security testing: OWASP Top-10 checklist, dependency audit (`composer audit`), authorisation tests for every route, file-upload abuse tests, and checks that police certificates cannot be reached without permission. Optionally, an external penetration test.
- Performance checks with seeded data (10k applicants).
- UAT with WaveTeam staff: a scripted test pack for each role, and a fix cycle.
- Production deployment:
  - DNS `portal.waveteam.lk`, TLS, `.env` secrets, cron, queue, backups (with a restore drill), monitoring
  - SPF, DKIM and DMARC for email
  - admin user handbook and an operations runbook
- Add the portal links to the WordPress menu (with your approval).

---

## Scheduled jobs (all from one cron entry: `* * * * * php artisan schedule:run`)
| Job | Schedule |
|---|---|
| `queue:work --stop-when-empty` (shared hosting) | every minute |
| `pcc:send-reminders` | daily 06:00 |
| `pcc:ops-expiry-digest` | Mondays 07:00 |
| `vacancies:close-expired` | daily 00:15 |
| `quotations:expire` | daily 00:20 |
| `backup:run` / `backup:clean` | daily 01:00 / 01:30 |
| `audit:prune-sessions`, `model:prune` | daily |

## Risks & mitigations
| Risk | Mitigation |
|---|---|
| Shared hosting limits (cron frequency, memory, SMTP limits) | VPS recommended. Otherwise a transactional email provider and the database queue |
| Reminder emails landing in spam | SES/Brevo with SPF, DKIM and DMARC; in-app notices as a fallback |
| Sensitive data breach | Encryption at rest, a separate permission for police certificates, mandatory admin 2FA, audit trail, CSP, backups |
| Legal non-compliance (PDPA 2022) | Consent records, retention policy, data-subject request workflow, lawyer review before launch |
| Scope growth | Phase sign-off; changes logged and scheduled into later phases |
