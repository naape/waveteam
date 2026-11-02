# Phase 7 Report — Police Clearance Verification & Renewal Automation

Status: **Complete (local development).** Nothing has been deployed.
Date: 2026-11-02

## How the brief's police clearance rules are met
| Requirement | How it is met |
|---|---|
| Upload a new certificate every year | Applicant upload page (Phase 4), plus reminders and an expiry notice (below) |
| Record issue date, upload date and next renewal date | `issue_date`, `uploaded_at`, `valid_until` and `next_renewal_due` on every record |
| Administrators configure the renewal rules | System settings: default validity, reminder days, "expiring soon" window |
| Reminders 30, 14 and 7 days before renewal | Daily job at 06:00 Asia/Colombo, with configurable days |
| Statuses: Valid, Expiring Soon, Expired, Pending Verification, Rejected | Worked out from the dates whenever displayed (never stale) |
| Expired certificates can be replaced | Upload a renewal; the old certificate stays in force until the renewal is approved |
| History of previous certificates is kept | Superseded certificates are marked, never deleted |
| Administrators are notified of every upload | Email to staff with `police_clearance.verify` |
| Only authorised administrators verify | `police_clearance.verify` is checked in the service itself, not only in the UI |
| Certificates are private | Encrypted vault; opening one needs `police_clearance.view` + 2FA; every view is audited |
| History is never deleted automatically | Records are append-only; nothing deletes them |
| An upload is never treated as verified until approved | Pending certificates are never valid for display, matching or assignment |
| The administrator sets the renewal date | The verifier enters the expiry (pre-filled with the suggestion) and an optional earlier renewal date, and can change the renewal date later with a reason |

## What was built

### Compliance queue (Admin → Applicants → Police clearance)
- **Views:** awaiting verification (default, with a red count badge), expiring soon, expired, all in force, rejected, and everything including history.
- **Open certificate:** opens the secure, audited viewer.
- **Verify:**
  - The verifier enters the **expiry date**, pre-filled from the issue date plus the configured validity (12 months by default), and an optional earlier **renewal date**.
  - The dates are checked: the expiry must be after the issue date and not in the past; the renewal date must be between today and the expiry.
  - The previously approved certificate is **superseded** (kept in history), the applicant's current certificate is updated, and the document is marked verified.
  - The applicant is emailed and notified in-app with their renewal date.
  - The audit entry records both the chosen date and the suggested default.
- **Reject:** the reason is required, emailed to the applicant and shown on their page.
- **Change renewal date:** for the certificate in force, for example when a client requires earlier renewal. A reason is required, and reminders restart against the new date.

### Renewal reminders (`pcc:send-reminders`, daily 06:00)
- Each reminder threshold (30, 14 and 7 days by default) is sent **at most once per certificate**. A unique database key guarantees this even if the job runs twice or on two servers.
- If several thresholds have already passed (the job was down, or the certificate was approved late), **only the most urgent reminder** is sent; the others are recorded as skipped.
- **No reminders while a renewal is awaiting verification**, but if the certificate expires before review, the expiry notice is still sent.
- After the renewal date, **one expiry notice** is sent.
- Superseded certificates and deactivated accounts are skipped.

### Eligibility
`PoliceClearanceEligibility` is the single rule for clearance-required sites. An applicant is eligible only if all of these hold:
- the applicant is active
- they have an approved certificate in force
- the renewal date covers the **whole assignment**

Expiry therefore makes someone ineligible **automatically**, with no batch flag to go stale. The rule exists both as a PHP check and as a database query for Phase 8 matching.

### Operations digest (`pcc:ops-expiry-digest`, Mondays 07:00)
Emails staff who can assign workforce a list of people on clearance-required assignments whose certificate is missing, expired, or due before the assignment ends (or within 30 days).

### Housekeeping
- `vacancies:close-expired` (00:15): closes published vacancies after their closing date.
- `quotations:expire` (00:20): marks sent quotations expired after their validity date.
- `auth:clear-resets` and `queue:prune-failed` run daily.

### Scheduler: one cron line on the server
```
* * * * * cd /path/to/portal && php artisan schedule:run >> /dev/null 2>&1
```
It also runs the queue worker every minute (`queue:work --stop-when-empty`), so shared hosting needs no long-running process.

### SMS and WhatsApp (ready, switched off)
- Channels: `SmsChannel` and `WhatsAppChannel`.
- Provider interface: `TextMessageGateway`. The `LogGateway` writes messages to the log with the number masked.
- Switched on with `SMS_ENABLED` / `WHATSAPP_ENABLED`, and only for templates enabled on that channel.
- Short SMS templates are seeded (renewal reminder, expiry, interview, assignment) and **disabled**.
- Adding Dialog, Mobitel, Twilio or the WhatsApp Business API is one adapter class plus one line in `AppServiceProvider`.
- Every message is recorded in `notification_logs`.

## Tests
**144 automated tests, all passing:** 127 earlier and 17 new. The new tests cover:
- approval with administrator-set dates, the suggested default being recorded, and the applicant notified
- all four date checks
- only Compliance Officers (or Super Admin) can verify; HR, Recruiters and Operations cannot
- a renewal superseding the old certificate while keeping its history, with the old one staying valid while the renewal is pending
- rejection requiring a reason and the reason shown to the applicant
- changing the renewal date (limited to the expiry date) resets reminders
- eligibility: pending never valid, must cover the whole assignment, automatic on expiry, blacklisted applicants excluded, and the database query matching the PHP check
- **the full reminder timeline**, simulated day by day: 30 → 22 (nothing) → 14 → 7 → due date (nothing) → expired → later (nothing), with each reminder sent exactly once
- a late approval sending only the most urgent reminder
- no reminders while a renewal is pending, but the expiry notice still sent
- configurable days, superseded certificates and deactivated accounts skipped
- the command being idempotent, with an email log entry
- the Operations digest including only at-risk assignments on clearance-required sites
- vacancy and quotation expiry jobs
- the scheduler registering every job in Colombo time
- SMS off by default, needing both the switch and an enabled template, and masking the number
- the admin queue actions run through the real Filament UI, and Recruiters forbidden from the queue

## Operations notes
- Add the cron line above on the server (Phase 10).
- `pcc:send-reminders` can be run manually at any time; it is safe to re-run.
- To use SMS: contract a provider, add its adapter, set `TEXT_MESSAGE_DRIVER` and `SMS_ENABLED=true`, then enable the SMS templates in Admin → Email templates.

## Next: Phase 8 (Workforce matching & assignments)
- **Find matches** from a service request: hard filters, including `PoliceClearanceEligibility`, then a weighted score using the weights in settings, with an explanation of why each person matched.
- **Assign people:** shortlist and assign, detect clashing assignments, override clearance only as Super Admin with a reason, and notify the applicant.
- **Team visibility:** the client sees the assigned team once WaveTeam allows it.
- **Assignment lifecycle:** proposed → confirmed → active → completed or released, with applicant availability updated automatically.
