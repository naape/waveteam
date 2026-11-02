# Phase 9 Report — Reporting & Analytics

Status: **Complete (local development).** Nothing has been deployed.
Date: 2026-11-02

## Report centre (Admin → Reports → Reports)
Pick a report, set its filters, check the summary figures and a 25-row preview, then **Export Excel** or **Export PDF**.

| # | Report (as named in the brief) | Filters | Summary figures |
|---|---|---|---|
| 1 | Total registered applicants | dates, category, district, verification | total, verified, active, new this month |
| 2 | Applicants by job category | dates, district | per trade: total, verified, clearance valid, available now |
| 3 | Applicants by district | dates, category | per district and province: total, verified |
| 4 | Verified and unverified applicants | dates, category, district, status | count per verification status |
| 5 | Valid and expired police clearance | category, district, clearance status | count per clearance status |
| 6 | Upcoming police clearance renewals | due within N days, category, district | due in 7 / 30 / N days, already expired; includes "renewal uploaded?" |
| 7 | Job applications | dates, category, district, status | count per application status |
| 8 | Selected applicants | dates, category, district | — |
| 9 | Registered organisations | dates, status | count per status; requests and completed per organisation |
| 10 | Service requests | dates, district, status | total, open, completed, cancelled, **accepted value (LKR)** |
| 11 | Completed projects | completion dates, district | team size and accepted value per project |
| 12 | Workforce assignments | start dates, district, status | count per status; flags clearance overrides |

**Exports:**
- **Excel (.xlsx):** a navy header row, frozen top row and auto-sized columns.
- **PDF:** the WaveTeam letterhead, a "Confidential — internal use" banner, the filters used, summary tiles, page numbers and who generated it. Landscape or portrait depending on the report.
- **Large exports:** above 2,000 rows (configurable), the export runs as a **background job**. The file is stored encrypted in the vault and the requester gets an email link that **only they** can open, and only while they still have `reports.export`. Others get "not found".
- **PDF limit:** PDFs are capped at 5,000 rows (configurable); larger reports should go to Excel.

### Privacy and security
- **No report ever contains NIC or passport numbers.** A test checks every report.
- Every export is **audit-logged** with the report name, format, row count, filters and whether it ran in the background. Downloads of background exports are logged too.
- Filter values are checked against an allow-list, so unknown keys and bad values (e.g. `1 OR 1=1`) are dropped before any query.
- **Access:** `reports.view` to open the page; `reports.export` to download.

| Role | Access |
|---|---|
| HR Manager, Compliance Officer, Operations Manager, Auditor, Super Admin | view and export |
| Finance | view only |
| Recruiter | no access |

## Dashboard charts
These are added under the existing KPI tiles. Each appears only to roles that can see that area. Charts are lazy-loaded so the dashboard opens quickly.
- **Applicants by job category:** all versus verified, per trade.
- **Police clearance status:** doughnut chart covering valid, expiring soon, expired, pending, rejected and not submitted.
- **Open service requests by stage.**
- **People deployed per month:** the last 6 months.

## Tests
**158 automated tests, all passing:** 152 earlier and 6 new. The new tests cover:
- all 12 reports run, row counts match, columns are consistent, and **no NIC appears in any report or summary**
- the figures are correct for seeded data: per-category and per-district counts, the unverified filter, renewals within 30 and 365 days (with days left), selected applicants only, date filters, completed project value and team size, accepted-value total, assignments, and a clearance status of "Expiring Soon"
- the Excel file opens with PhpSpreadsheet, with the right headings and row count; the PDF renders; the PDF row cap is enforced
- the reports page through Livewire: filtering by district changes the preview, the Excel download works, the export is audited with its filters, and hostile filter values are dropped
- **background exports:** queued above the limit, the job stores an encrypted file, the requester is emailed a link, the requester can download, and another HR user or an applicant gets "not found"
- **permissions:** Recruiter blocked, Finance can view but not export, and the four chart widgets render with correct data

The reports page and dashboard were also checked visually. A temporary staff account was used for the screenshots and then removed. That check led to one fix: the category chart was skipping every other label.

## Settings
- `REPORT_INLINE_LIMIT` (default 2000): the number of rows above which exports run in the background.
- `REPORT_PDF_LIMIT` (default 5000): the maximum rows for a PDF.

## Next: Phase 10 (Security testing, UAT & production deployment)
- **Security hardening:**
  - move to a nonce-based Content Security Policy
  - run `composer audit` and the npm audit
  - complete the OWASP Top-10 checklist
  - test upload abuse, authorisation on every route and rate limits
  - review session and cookie settings
- **Performance check** with roughly 10,000 seeded applicants.
- **UAT pack:** test scripts for each role.
- **Production deployment** (needs your decisions on hosting and email):
  - DNS for `portal.waveteam.lk`, TLS, `.env` secrets and the key backup procedure
  - the cron line, encrypted off-site backups with a restore drill, and monitoring
  - SPF, DKIM and DMARC for email
  - finally, the menu links on the WordPress site
