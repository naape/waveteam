# Phase 8 Report — Workforce Matching & Assignments

Status: **Complete (local development).** Nothing has been deployed.
Date: 2026-11-02

## What was built

### Find workforce (Admin → Service request → "Find workforce")
A page per service request showing:
- a summary of the request: period, location, team count against headcount, and the clearance, experience and skill requirements
- the current team
- **ranked candidates**, each with a score from 0 to 100, a bar for each scoring factor, plain-English reasons and warnings
- an **Assign** button on each candidate

**Hard filters.** A candidate must pass all of these to appear:
- active account and active applicant (not on hold or blacklisted)
- **verified profile**, always required
- in a **job category that can deliver the service category**. The mapping is in `config/portal.php`; for example, "Tower Construction" maps to tower and civil workers. Technical Manpower Supply accepts any category.
- **available on the start date**: available now, or available from a date on or before the start
- **no clashing assignment**: proposed, confirmed or active assignments that overlap the period, so someone already engaged elsewhere can still appear for dates when they are free
- not already on this request
- **when clearance is required:** an approved certificate in force covering the **whole period** (the Phase 7 rule). Pending, rejected, expired or soon-lapsing certificates are excluded.
- *Option:* must meet the minimum experience (on by default)
- *Option:* only people living in, or willing to work in, the request's district

**Score**, using the weights in System settings (default in brackets):
- **Skills (40%):** each requested skill the person has counts in full if they meet its minimum years, and half if they don't.
- **Experience (25%):** years held against years required.
- **Qualifications (15%):** certifications and education.
- **Location (15%):** the district is in their preferred districts (100%), they live there (80%), or the same province (50%).
- **Profile completeness (5%).**

Each candidate also gets reasons (e.g. "Skills: 1 of 1 required (Rigging)", "Police clearance valid until 30 Jun 2027") and warnings (e.g. below the requested experience, clearance renewal falling soon after the assignment, currently engaged elsewhere).

### Assignment rules (`AssignmentActions`)
**Assigning** checks everything again on the server, so the matching screen can't be bypassed:
- the person has `workforce.assign`
- the request is Approved, Team Assigned or In Progress
- the applicant is verified and active
- there is no clash
- clearance is eligible when required

The assignment is created as **Proposed**, with a **snapshot of the certificate** in force, and the applicant is notified by email and in-app.

**Clearance override.** The brief requires only eligible people on clearance sites. In a genuine emergency a **Super Admin** (`workforce.override_pcc`) can assign anyway: they enter the applicant's NIC and a mandatory reason, the assignment is flagged as an override, and the reason goes in the audit log. Operations Managers cannot override.

**Lifecycle** (Admin → Workforce → Assignments, with a badge counting proposed assignments):

| From | Can move to |
|---|---|
| Proposed | Confirmed, Cancelled |
| Confirmed | Active ("on site"), Released, Cancelled |
| Active | Completed, Released |

- Release and cancel require a reason.
- The first confirmation moves the request to **Team Assigned**; the first start moves it to **In Progress**.
- **Starting re-checks clearance on the day**: someone whose certificate lapsed since being proposed cannot go on site.
- The applicant's availability is **Engaged** while active, and goes back to **Available** when their last assignment ends.
- Every change is audited and the applicant is notified.

### Who sees what
- **Applicant dashboard:** a "My assignments" card with role, project, location, dates and status.
- **Client:** the team appears only when WaveTeam enables "show team to client" (Phase 6), and then only name, role, dates and the "verified" badge.
- **Service request admin page:** a new **Team** section.
- **Permissions:** Find workforce needs `workforce.match`; assigning and lifecycle changes need `workforce.assign`. Finance and Recruiters are blocked.

## Tests
**152 automated tests, all passing:** 144 earlier and 8 new, covering:
- **Every hard filter:** unverified, blacklisted, wrong trade, unavailable, available too late versus in time, no clearance, clearance lapsing mid-assignment, pending clearance, too junior (and the option that relaxes it), and a clashing booking. Also: without a clearance requirement uncleared people are allowed, but verification is still required.
- **Ranking and explanations:** best candidate first with a score of 100, half credit for skills below the minimum years, the reason texts, and the weights read from settings.
- **Assignment rules:** proposed with a certificate snapshot and notification, refused on clash, unverified, lapsing clearance, missing permission, an Operations Manager attempting an override, or a request not yet approved.
- **Super Admin override:** reason stored and audited.
- **Lifecycle:** request moves to Team Assigned then In Progress; availability Engaged then Available; no going backwards; release needs a reason; **start blocked when clearance expired after proposal**.
- **Visibility:** applicant dashboard, and the client's team view switch.
- **Real admin UI through Livewire:** the matching page renders, the Assign action with arguments, the lifecycle action in the assignments list, and Finance forbidden from both.

## Notes
- **Category mapping:** the service-to-job-category mapping is configuration. Adjust it in `config/portal.php` if WaveTeam staffs services differently. It could become an admin screen later.
- **Performance:** scoring runs in PHP after the hard filters in SQL, which suits a few thousand applicants. For much larger pools, the score could move into SQL.

## Next: Phase 9 (Reporting & analytics)
- **Downloadable reports**, each filterable and exportable to **PDF and Excel**: total registered applicants; applicants by job category and by district; verified and unverified; valid and expired police clearance; upcoming renewals; job applications; selected applicants; registered organisations; service requests; completed projects; workforce assignments.
- **Dashboard charts.**
- **Large exports** run as a queued job, with the download link sent by email.
