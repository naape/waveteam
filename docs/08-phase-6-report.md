# Phase 6 Report — Administrator Dashboard

Status: **Complete (local development).** Nothing has been deployed.
Date: 2026-11-02

## Scope note
Police clearance **verification** (approve or reject, and setting the renewal date) stays in **Phase 7**, as the approved plan says, alongside the renewal reminders. The dashboard already counts pending, expiring and expired certificates, and applicants can be filtered by police clearance status.

## Admin modules
Every module is permission-gated: the menu, pages and each individual action are checked on the server, and anything not explicitly allowed is denied. Staff only see the menu items their role allows.

| Area | Module | What staff can do | Permission |
|---|---|---|---|
| **Dashboard** | Overview | See KPI tiles: applicants, documents to verify, police clearance (pending, expiring, expired), new applications, open requests, projects in progress, quotations (awaiting client, drafts), organisations awaiting verification. Tiles link to filtered lists. Also a "Service requests needing attention" table. | Per tile |
| **Applicants** | Applicants | Search by name or email, and **exact NIC match through the blind index**. Filter by job category, district, preferred district, minimum experience, verification, police clearance status, applicant status and availability. Full profile in 5 tabs. Verify or reject the profile; change applicant status (reason required); open the CV. **NIC and passport are masked** without `applicants.view_pii`. Each profile view is audited. | `applicants.view` / `.verify` / `.change_status` |
| | Document verification | Queue of current CVs, NIC copies, certificates and licences. Open, verify, reject (the reason is emailed to the applicant and shown in their portal), or bulk-verify. Shows a count badge. | `documents.view` / `.verify` |
| **Recruitment** | Vacancies | Create, edit, publish and close vacancies. Slugs are unique and never change, so shared links keep working. Application counts link to the filtered list. | `vacancies.manage` |
| | Applications | Filter by vacancy and status. Opening a new application marks it *Under review*. Change status for one or many, with an optional applicant email. **Schedule an interview**: a new one marks any earlier booking *Rescheduled* and the applicant is invited by email and in-app. | `applications.view` / `.manage`, `interviews.manage` |
| | Interviews | Upcoming list. Record the outcome (completed, no-show, cancelled), a score and notes. | `interviews.manage` |
| **Clients & Services** | Organisations | Verify, suspend (reason emailed), reactivate, edit details. Contacts, supporting documents and requests on one page. | `organizations.view` / `.manage` / `.verify` |
| | Service requests | Default view shows "Open only"; filters include "Assigned to me". The request page has full details, quotations, the **conversation including internal notes**, attachments and history. See the actions below. | `service_requests.view` / `.manage`, `quotations.*` |
| | Quotations | List with draft count badge. Edit drafts, **approve & send**, download PDFs. | `quotations.view` / `.manage` / `.approve` |
| **Administration** | Roles | Edit any role's permissions as a grouped checklist; changes are audited. Super Admin is fixed. | `roles.manage` |
| | Email templates | Edit the wording of every email. **Mandatory** templates (security, compliance) can't be switched off. | `notifications.manage` |
| | System settings | Police clearance validity, "expiring soon" window and reminder days; maximum upload size; staff idle timeout; matching weights (must total 100). Changes are audited. | `settings.manage` |
| | Staff, Portal accounts, Audit log | From Phase 3. | |

**Actions on the service request page:**
- **Reply to client**, with attachments; the client is notified by email and in-app.
- **Prepare / edit a quotation**: line items, tax %, validity date, terms, and a live total preview. The server recalculates every amount.
- **Approve & send**, which needs `quotations.approve`.
- **Change status**, restricted to valid next steps; cancelling requires a reason.
- **Internal note**, never shown to the client.
- **Assign manager**: only staff who can manage requests can be chosen.
- **Add attachment**, with a choice of client-visible or staff-only.
- **Show or hide the team** from the client.

### Quotation workflow
1. **Draft:** the reference format is `QT-2026-0001` and versions number v1, v2 and so on. Saving the first draft moves the request to *Quotation preparation*.
2. **Approve & send:** only staff with `quotations.approve` (Finance or Super Admin) can do this.
   - It generates a **branded A4 PDF** (WaveTeam letterhead, line items, totals, terms) with dompdf.
   - The PDF is stored **encrypted** in the vault.
   - Any earlier sent version is superseded.
   - The request moves to *Quotation sent* and the organisation's contacts are notified.
3. The client accepts or rejects it in their portal (Phase 5). The full loop is covered by a test.

## Bugs found and fixed during this phase
1. **Filters silently doing nothing.** Filament passes closure arguments **by parameter name** (`$query`, `$state`, `$search`). A filter written as `fn (Builder $q)` instead received a *new, empty* query from the service container, so filters such as "Open only", the experience filter and the date ranges appeared to work but changed nothing. Eight closures were renamed, five `formatStateUsing` closures were fixed (one in the Phase 3 audit-log view, which had never been rendered by a test), and a regression test checks that filters really filter.
2. **N+1 queries.** Laravel's strict mode caught them on the detail pages. Each view page now eager-loads exactly what it shows.
3. **Broken staff avatar.** Filament's default avatar comes from ui-avatars.com, which the security policy blocks, and which would also send staff names to a third party. It is replaced with a locally generated initials avatar.
4. **Staff couldn't open organisation files.** Admin links to organisation files would have used the client-only route. A shared `/files/{uuid}` route now applies the same access rules plus the staff 2FA requirement.

## Tests
**127 automated tests, all passing:** 111 earlier and 16 new. The new tests cover:
- every admin page rendering for a Super Admin (27 URLs, including detail and edit pages)
- the **permission matrix** for Recruiter, Operations Manager, Finance and Compliance Officer, including masked NIC for Recruiters
- profile verification
- document rejection with a reason shown to the applicant
- police clearance excluded from the document queue
- status changes and interview scheduling, including rescheduling and refusing past dates
- organisation verification and suspension
- request status transitions and refusal of invalid ones
- internal notes and staff-only attachments hidden from clients
- manager eligibility
- **quotation maths** (rounding and 18% tax), drafts invisible to clients, the approve permission, PDF generation and decryption, the client accepting, and revisions superseding earlier versions
- Filament UI actions through Livewire: prepare and send a quotation, reply and change status, unique vacancy slugs, settings validation and save, role permission editing (Super Admin locked), and filters actually filtering

The dashboard, request page and applicant pages were also checked visually. A temporary staff account was created for the screenshots and then removed.

## Notes
- Livewire keeps admin uploads briefly in a private temporary folder (`storage/app/private/livewire-tmp`) before they are encrypted into the vault. Livewire removes these files automatically.
- Quotation PDFs use the DejaVu Sans font, which supports Sinhala/Tamil only partly. A Sinhala or Tamil quotation template would need a matching font; this is noted for the localisation work.

## Next: Phase 7 (Police clearance verification & renewal automation)
- **Compliance queue:** view the certificate, then approve (set or confirm the expiry and renewal date) or reject with a reason.
- **Daily reminder job:** reminders 30, 14 and 7 days before renewal, then an expiry notice; each sent only once.
- **Expiry effects:** applicants with expired certificates become ineligible for assignments that need clearance.
- **Weekly digest for Operations:** assignments whose certificates expire mid-project.
- **Scheduler set-up:** a single cron line runs everything.
