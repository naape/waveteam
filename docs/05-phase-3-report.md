# Phase 3 Report — Authentication & Registration

Status: **Complete (local development).** Nothing has been deployed.
Date: 2026-11-01

## What was built

### Separate portals
| | Applicant Portal | Service Request Portal | Admin |
|---|---|---|---|
| Landing page | `/applicants` | `/organizations` | — |
| Register | `/applicants/register` | `/organizations/register` | Invite only (Super Admin) |
| Sign in | `/applicants/login` | `/organizations/login` | `/admin/login` + mandatory 2FA |
| Forgot password | `/applicants/forgot-password` | `/organizations/forgot-password` | Admin panel's own flow |

- Credentials only work in their own portal. An applicant who tries the organisation sign-in is told which portal to use, but **only after entering the correct password**. Without the password, every failure gets the same generic message, so the form can't reveal which emails are registered.
- **Staff accounts cannot sign in through either public portal.** Signed-in users who open the other portal's pages are sent back to their own dashboard.
- Fortify was removed, because its single generic `/login` would have bypassed the portal separation. Authentication now uses Laravel's core features directly.

### Registration
- **Applicants**:
  - Fields: full name, NIC (old or new format), mobile number, email, password.
  - Three consent boxes: terms; privacy and retention policy; explicit consent for NIC and police clearance processing.
  - **Duplicate NICs are refused** using the blind index, so the encrypted NIC is never decrypted for the check.
  - Phone numbers are normalised to +94 format.
- **Organisations**:
  - Organisation details, type, optional business registration number, address and billing address ("same as address" option), plus the contact person and their designation.
  - The organisation is created with status *Pending* until WaveTeam approves it.
- Fields such as account type, status and verification **cannot be injected** through the form. This is tested.
- Every consent is stored with the **exact policy version**, a timestamp, IP address and browser, as PDPA proof of consent.

### Account security
- **Email verification** with signed links. Both portals' dashboards stay locked until the email address is verified.
- **Password reset** links are sent only for active accounts of that portal's type, with the same response whether or not the email exists. A reset signs the account out of every device.
- **Throttling:** 5 attempts per minute per email and IP, and a lockout after 10 failures in 15 minutes. Lockouts are audited.
- **Account security page:**
  - change password (signs out other devices)
  - list of signed-in devices, with "sign out other devices"
  - the last 10 security events
- **Staff idle timeout** of 30 minutes (configurable in settings), after which the staff member is signed out of the admin panel.

### Admin portal: new modules
- **Staff** (`staff.manage`):
  - Invite staff by email. They set their own password, then must enrol 2FA.
  - Assign roles. Only a Super Admin can grant Super Admin or edit a Super Admin. Nobody can edit their own roles.
  - Deactivate or reactivate, with the reason audited.
  - Super Admin can reset a colleague's 2FA (for example after a lost phone).
- **Portal accounts**: deactivate or reactivate applicant and organisation logins. HR can manage applicants; Operations can manage organisations.
- **Audit log** (`audit_logs.view`, read-only), with filters for category, actor type and date range.
- **Deactivation** takes effect immediately. It:
  - deletes all of the user's sessions
  - revokes their API tokens
  - clears remember-me
  - is audited with a mandatory reason

### Audit trail
Every sign-in, failed sign-in (email only, never the password), lockout, sign-out, registration, email verification, password reset and change, session revocation, idle timeout, staff change, 2FA reset and account status change is logged. Admin panel sign-ins are included.

### Email
- Sent from `no-reply@waveteam.lk`, with the WaveTeam logo and navy buttons.
- Verification, welcome and organisation confirmation emails use the **admin-editable templates**.
- Every email sent or failed is recorded in `notification_logs`.
- Locally, emails are written to `storage/logs/laravel.log` instead of being sent.

### Draft policies (v0.1-draft, published)
- `/privacy-policy`: written against the Personal Data Protection Act No. 9 of 2022. Police clearance data is treated as a special category.
- `/terms`
- `/data-retention`

All three carry a **"Draft for legal review"** banner. Legal changes must be published as a **new version**, never edited in place, because consent records point to specific versions.

## Tests
**63 automated tests, all passing:** 24 from Phase 2 and 39 new. They cover:
- registration (both portals)
- consent enforcement
- NIC format and duplicate checks
- refusal of injected fields
- portal-specific sign-in and the wrong-portal message
- staff blocked from public portals
- deactivated accounts refused
- rate limiting and lockout
- forced email verification
- signed and tampered verification links
- password reset per portal and protection against email enumeration
- password change and signing out other devices
- policy pages
- admin permission matrix
- deactivation ending sessions and tokens
- Super Admin protections
- staff idle timeout

The full flow was also exercised manually against the running server: register → verification email → blocked until verified → verify → dashboard.

## Decisions I made (change them if you prefer)
- Organisations start as **Pending**, but can still sign in and see their dashboard. Whether a pending organisation may *submit* service requests is decided in Phase 5.
- An applicant must verify their email before they see the dashboard.
- Staff are invited, never given a password by an admin.

## Carried forward
- Supporting documents at organisation registration move to the organisation profile in Phase 5, because they need the encrypted upload service built in Phase 4.
- Optional 2FA for applicant and organisation accounts is not built. Admin 2FA is mandatory and working.
- The CSP still allows `'unsafe-inline'` (Phase 10).

## Next: Phase 4 (Applicant Portal)
- profile wizard (personal details, education, qualifications, skills, employment, languages, licence, military service, preferences)
- profile completion percentage
- encrypted document upload and versioning
- police clearance upload
- vacancies, search and applications
