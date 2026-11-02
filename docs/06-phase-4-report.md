# Phase 4 Report — Applicant Portal

Status: **Complete (local development).** Nothing has been deployed.
Date: 2026-11-01

## What was built

### Profile wizard (9 steps, with a progress sidebar)
1. **Personal details**
   - Name, date of birth, gender (optional), passport (optional), permanent and current address, district, mobile and WhatsApp numbers, emergency contact, profile photo.
   - The date of birth is **checked against the birth year encoded in the NIC**.
   - The NIC is read-only for applicants; corrections go through WaveTeam.
2. **Education**: qualification, institution, year, results (multiple entries).
3. **Certifications & training**: professional, technical or specialised training, issuer, issue and expiry dates.
4. **Skills & experience**: job categories, total years of experience, and skills from the controlled list (55 skills in 11 categories), each with years and level. The controlled list keeps matching reliable in Phase 8.
5. **Employment history**: employer, designation, dates, "currently working here", responsibilities.
6. **Languages**: speaking, reading and writing level. Each language can be added once.
7. **Driving licence**: the number is **encrypted**, plus Sri Lankan vehicle classes and expiry date.
8. **Military service** (optional).
9. **Preferences**:
   - preferred working districts, grouped by province
   - availability: available now, from a date, or not available
   - "Engaged" status can only be set by WaveTeam

The six repeatable sections are defined once in `ProfileSections` and served by one generic controller. They therefore all share the same ownership scoping: one applicant can never see or change another's entries (404).

### Profile completion
The completion percentage is weighted:

| Item | Weight |
|---|---|
| Personal details | 20 |
| Skills | 15 |
| Education | 10 |
| Employment | 10 |
| CV | 10 |
| NIC copy | 10 |
| Police clearance | 10 |
| Photo | 5 |
| Languages | 5 |
| Preferences | 5 |

The dashboard shows the checklist with direct links to whatever is missing.

### Document centre: encrypted at rest
- **Types:** CV, NIC copy, educational certificates, professional certificates, driving licence, other.
- **File type is checked from the actual bytes** (finfo plus our own check of the file signature), not from the extension or browser-supplied type. PDF, JPG and PNG only; 5 MB limit (an admin setting).
- **Encryption:**
  - Every file is encrypted with libsodium XChaCha20-Poly1305, which also detects tampering.
  - The key is `DOCUMENT_ENCRYPTION_KEY`, separate from `APP_KEY`.
  - Files are stored under random UUID names on a private disk.
- **Versioning:** a new CV, NIC or licence copy supersedes the old one, which is kept in history. Certificates can have several current files.
- **Secure viewer** (`/documents/{id}/file`) is the only way a file leaves the server. Every view and download is audited, and the policy rules are:

| Who | Can open |
|---|---|
| The applicant | Their own documents |
| Staff with `documents.view` | CVs and certificates |
| Staff with `police_clearance.view` | Police clearance certificates (not given to Recruiters) |
| Staff who have not set up 2FA | Nothing (blocked from the viewer outside the admin panel too) |
| Organisation contacts | Nothing |

- **Response headers:** `no-store` caching, plus a `sandbox` CSP so a malicious PDF can't run scripts on our domain.

### Police clearance (applicant side)
- **Upload:** issue date (not in the future and not older than 3 years), certificate number (encrypted), issuing authority, file, and a "genuine and unaltered" declaration.
- **Status:**
  - A new upload starts as **Pending Verification** and never counts as valid until approved.
  - An already-approved certificate **stays in force** until the renewal is approved.
  - A second pending upload replaces the first in the verification queue; both are kept in history.
- **Notifications:** staff with `police_clearance.verify` are emailed on every upload.
- **Page:** the current status, the next renewal date with days remaining, the full certificate history, and rejection reasons.

### Vacancies & applications
- **Search** by keyword, category and district. Wildcards in search terms are treated as literal text. Paginated.
- **Vacancy page:** location, type, positions, minimum experience, closing date and police clearance requirement. Text is escaped.
- **Applying:**
  - Requires a CV. A **snapshot** of the current CV is attached, so later CV uploads don't change past applications.
  - The applicant can apply only once per vacancy, and only while it is open.
  - A warning is shown if the role needs police clearance and the applicant doesn't have it.
  - Each application gets a status history and a confirmation email.
- **My applications:** status badge, progress timeline and scheduled interviews.

### Dashboard and notifications
- **Dashboard:**
  - completion checklist
  - police clearance status with next renewal date
  - document summary
  - notifications
  - recent applications
  - suggested vacancies in the applicant's categories
- **In-app notifications:** every templated email to a portal user also appears on the dashboard (unread markers, mark all read). Interview invitations and assignment notices from Phases 6–8 will appear here automatically.

## Bugs found and fixed during this phase
1. **Shared rate-limit counter.** Laravel's `throttle:N,1` keys on the user ID alone, so *every* throttled route shared one counter. An applicant who saved a few profile sections got "Too many requests" on their first police clearance upload. All routes, including Phase 3's, now use **named per-action limiters**. A regression test covers it.
2. **Wrong table name.** Laravel treats "education" as uncountable, so the model looked for the wrong table. The table name is now set explicitly.
3. **Wrong collection method.** `Eloquent\Collection::only()` filters by model ID, not by array key; the dashboard now uses the base collection.

## Tests
**95 automated tests, all passing:** 63 earlier and 32 new. The new tests cover:
- encryption round-trip, including large multi-chunk files
- rejection of disguised PHP and HTML files and oversized files
- tamper detection
- profile save and normalisation
- the NIC birth-year check
- NIC immutability
- section CRUD, cross-applicant isolation, validation and uniqueness
- encrypted licence numbers
- skills sync ignoring unknown IDs
- the full document access matrix
- the police clearance pending, replace and still-in-force rules
- verifier notification
- search, filters and hidden draft or closed vacancies
- one application per vacancy with a CV snapshot
- in-app notification isolation

The full applicant journey was also run against the dev server through the real forms and checked visually.

## Notes and operations
- **`DOCUMENT_ENCRYPTION_KEY` must be backed up securely.** If it is lost, every uploaded document is unreadable. Keep it separate from database backups.
- **Demo vacancies:** run `php artisan db:seed --class=DemoVacancySeeder` (refuses to run in production). Staff vacancy management arrives in Phase 6.
- **CSS rebuild:** after changing any Blade view, run `npm run build` (or keep `npm run dev` running) so Tailwind includes the new classes.

## Next: Phase 5 (Service Request Portal)
- organisation profile and supporting documents
- multi-step service request form with attachments
- request tracking
- quotations (accept or reject)
- messaging with WaveTeam
- completed history and downloads
