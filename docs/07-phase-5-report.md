# Phase 5 Report — Service Request Portal

Status: **Complete (local development).** Nothing has been deployed.
Date: 2026-11-02

## Decision recorded
**Pending organisations can submit service requests.** WaveTeam reviews the organisation together with its first request, and the staff email shows the organisation's status. **Suspended** organisations cannot submit. This rule lives in one place (`SubmitServiceRequest`), so it is easy to change.

## What was built

### Organisation profile
- **Editable:** address, billing address, phone, and the contact person's name, designation and direct phone.
- **Locked after verification:** once WaveTeam verifies the organisation, its **name, type and registration number** can only be changed by WaveTeam.
- **Supporting documents:** for example a business registration certificate or authorisation letter. Uploads are encrypted and stored in the same vault as applicant documents.

### Service request form
The form has four sections, covering every field in the brief:
1. **Service:** category (the 10 service types), project title, description.
2. **Team:** number of technicians, minimum experience, required qualifications, and optional specific skills from the same skill list applicants use, so Phase 8 matching works on both sides.
3. **Location & schedule:** district, sites or address, preferred start date, expected duration (days, weeks or months), working hours.
4. **Requirements & attachments:**
   - whether police clearance is required (defaults to yes)
   - special requirements
   - optional budget in LKR
   - **up to 5 attachments**: PDF, JPG, PNG, and macro-free Word (.docx) or Excel (.xlsx), checked by content

Each request gets a reference like **SR-2026-00042**, which is also used in its URL. On submission:
- a status-history entry and an audit log entry are recorded
- the client gets a confirmation email and an in-app notification
- staff with `service_requests.manage` get an email

### Request tracking
- **List** with filters for All, Open, Completed (service history) and Cancelled.
- **Request page:**
  - a progress bar through the 9 statuses
  - all request details
  - documents and status history
  - **quotations:** line items, subtotal, tax, total, validity date, terms, and a PDF download once the admin side creates PDFs
  - **accept** (requires an "I am authorised" confirmation) or **reject** (requires a reason)
  - **assigned team**, shown only when WaveTeam enables "show team to client", and then only name, role, dates and a "WaveTeam verified" badge (no personal data)
  - **messages** with WaveTeam, including file attachments; the assigned manager is emailed, or all request managers if none is assigned
  - **cancel** with a reason, allowed only before work is approved
- **Dashboard:**
  - counters: open requests, quotations awaiting a decision (highlighted), teams on site, completed projects
  - recent requests
  - unread WaveTeam messages
  - notifications

### Quotation rules
A quotation can be answered only if all of these hold:
- it has status *Sent*
- it is within its validity date
- the request is at *Quotation Sent*

A quotation can be answered only once, and draft quotations are invisible to clients. Responses change the request status as follows, and Finance (`quotations.manage`) is notified either way:
- **Accept:** the request moves to *Approved*
- **Reject:** the request goes back to *Under Review*

### Secure files for organisations
`/organizations/files/{uuid}` serves a file only if it is attached to something the user is entitled to see. Every other case returns **404**, so file IDs can't be probed:

| File | Shown to the organisation when |
|---|---|
| Supporting document | It belongs to the user's organisation |
| Request attachment | It is marked visible to the client |
| Message attachment | The message is not an internal staff note |
| Quotation PDF | The quotation has been sent (never drafts) |

All other files, including other organisations' files, return 404. Word and Excel files are **always downloaded, never displayed** in the browser.

Internal staff notes on a request are never shown to the client. This is tested.

## Bugs found and fixed during this phase
- **Police clearance default not selected.** "Yes" wasn't pre-selected on the new request form because PHP turns array keys `'1'` and `'0'` into integers, so the strict comparison failed. Fixed, and a check was added to the tests.
- **Unsaved billing address.** Saving the profile with an empty billing address raised an error. It now falls back to the main address.

## Tests
**111 automated tests, all passing:** 95 earlier and 16 new. The new tests cover:
- submission with PDF and Word attachments
- reference format and history
- who gets notified (Operations yes, Finance no)
- validation, and rejection of a fake .docx
- a suspended organisation blocked
- a colleague in the same organisation allowed; another organisation forbidden; an applicant redirected
- messaging and staff notification
- internal notes hidden
- the cancellation window
- the team visibility switch
- profile identity lock
- dashboard counters
- accepting and rejecting quotations, including authorisation confirmation, rejection reason and no double response
- expired, draft and other-organisation quotations
- the full file-access matrix (9 cases)
- Office files forced to download

## Demo data (local only)
`php artisan db:seed --class=DemoOrganizationSeeder` creates **Lanka Telecom Contractors** (`kumari@ltc.lk` / `SecurePass123`) with three requests:
- one newly submitted
- one with a quotation awaiting a decision (LKR 3.03M, 3 line items)
- one completed, with an assigned team

## Carried forward to Phase 6 (admin)
Staff will need these, all of which plug into the actions built here:
- reviewing requests and changing their status
- writing quotations, generating a PDF and sending it to the client
- replying to clients and writing internal notes
- toggling team visibility
- verifying organisations

## Next: Phase 6 (Administrator Dashboard)
The Filament admin modules:
- overview KPIs
- applicants and document verification
- vacancies, applications and interviews
- organisations, service requests and quotations
- staff, roles, notification templates and settings
