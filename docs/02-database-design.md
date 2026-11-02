# WaveTeam Portal — Database Architecture (proposed)

MySQL 8, InnoDB, `utf8mb4_unicode_ci`. Every table has `id BIGINT UNSIGNED PK`, `created_at` and `updated_at` unless noted otherwise. `FK` = foreign key with an index. Soft deletes (`deleted_at`) are used on business records. Nothing that has legal value is hard-deleted.

Notation: 🔒 = encrypted column, 🔑 = unique.

---

## 1. Entity overview

```
users ─┬─ applicant_profiles ─┬─ applicant_educations
       │                      ├─ applicant_certifications (professional/technical/training)
       │                      ├─ applicant_skills ──► skills
       │                      ├─ applicant_job_categories ──► job_categories
       │                      ├─ applicant_employments
       │                      ├─ applicant_languages
       │                      ├─ applicant_driving_licences
       │                      ├─ applicant_military_services
       │                      ├─ applicant_preferred_districts ──► districts
       │                      ├─ applicant_documents ──► stored_files
       │                      ├─ police_clearance_records ──► applicant_documents
       │                      │        └─ police_clearance_reminders
       │                      ├─ job_applications ──► job_vacancies ──► job_categories
       │                      │        ├─ job_application_status_history
       │                      │        └─ interviews
       │                      └─ workforce_assignments ◄── service_requests
       │
       ├─ organization_users ──► organizations ─┬─ organization_documents ──► stored_files
       │                                        └─ service_requests ─┬─ service_request_status_history
       │                                                             ├─ service_request_attachments
       │                                                             ├─ service_request_messages
       │                                                             ├─ quotations ── quotation_items
       │                                                             └─ workforce_assignments
       │
       ├─ (staff) roles / permissions (spatie)
       ├─ consent_records ──► policy_versions
       ├─ notifications (in-app) · notification_logs · notification_templates
       └─ audit_logs
settings · districts · service_categories · skills · job_categories
```

---

## 2. Identity & access

**users**
| column | type | notes |
|---|---|---|
| user_type | ENUM('applicant','org_contact','staff') | decides which portal the user can access |
| name | VARCHAR(150) | |
| email 🔑 | VARCHAR(190) | |
| email_verified_at | TIMESTAMP NULL | |
| password | VARCHAR(255) | Argon2id |
| two_factor_secret 🔒, two_factor_recovery_codes 🔒, two_factor_confirmed_at | | mandatory for staff |
| status | ENUM('active','suspended','deactivated') | |
| locale | CHAR(2) default 'en' | en / si / ta |
| last_login_at, last_login_ip | | |
| deactivated_at, deactivated_by FK users | | |

Also: `password_reset_tokens`, `sessions` (database driver), `personal_access_tokens` (Sanctum), and the spatie tables `roles`, `permissions`, `model_has_roles`, `model_has_permissions` and `role_has_permissions`.

**policy_versions**: `type ENUM('privacy','terms','retention')`, `version`, `body`, `published_at`.
**consent_records**: `user_id FK`, `policy_version_id FK`, `consented_at`, `ip`, `user_agent`, `withdrawn_at NULL`.

---

## 3. Reference / lookup data
- **districts**: the 25 districts of Sri Lanka (`name_en`, `name_si`, `name_ta`, `province`).
- **job_categories**: `name`, `slug 🔑`, `description`, `is_active`, `sort`.
- **service_categories**: `name`, `slug 🔑`, `is_active`. Seeded with the 10 categories in the brief.
- **skills**: `name 🔑`, `job_category_id FK NULL`. A controlled list, so matching is reliable. Admins can add to it.
- **settings**: `key 🔑`, `value JSON`, `updated_by`. Examples: `pcc.default_validity_months = 12`, `pcc.reminder_days = [30,14,7]`, `pcc.expiring_soon_days = 30`, `uploads.max_mb = 5`.

---

## 4. Applicant domain

**applicant_profiles** (1:1 with users)
| column | notes |
|---|---|
| user_id FK 🔑 | |
| full_name | |
| nic_number 🔒, nic_hash 🔑 | blind index (HMAC-SHA256) for duplicate detection and search |
| passport_number 🔒 NULL | |
| date_of_birth, gender NULL | |
| permanent_address, current_address | TEXT |
| district_id FK | |
| contact_number, whatsapp_number | E.164 format |
| emergency_contact_name, emergency_contact_number | |
| photo_file_id FK stored_files NULL | |
| total_experience_years | DECIMAL(4,1). Derived, but stored for fast filtering |
| availability_status | ENUM('available','available_from','engaged','unavailable') |
| available_from | DATE NULL |
| profile_completion | TINYINT. Recalculated on save |
| verification_status | ENUM('unverified','pending','verified','rejected') |
| verified_by FK users, verified_at | |
| applicant_status | ENUM('active','on_hold','blacklisted','inactive') |
| current_police_clearance_id FK NULL | pointer to the latest approved record |

Repeatable child tables (all have `applicant_profile_id FK` and `sort`):
- **applicant_educations**: qualification, institution, year_completed, result_grade, `document_id FK NULL`, `verified_at`, `verified_by`.
- **applicant_certifications**: `kind ENUM('professional','technical','training')`, title, issuing_body, issued_on, expires_on NULL, `document_id FK NULL`, verified_*.
- **applicant_skills**: `skill_id FK`, years, `level ENUM('basic','intermediate','expert')`, unique(applicant, skill).
- **applicant_job_categories**: the categories the applicant wants work in (many-to-many).
- **applicant_employments**: employer, designation, start_date, end_date NULL, is_current, responsibilities.
- **applicant_languages**: language, `speak/read/write ENUM('basic','good','fluent')`.
- **applicant_driving_licences**: licence_number 🔒, vehicle_classes (JSON, e.g. ["B1","B","C1"]), expires_on, `document_id FK NULL`.
- **applicant_military_services**: branch, rank, service_from, service_to, notes.
- **applicant_preferred_districts**: `district_id FK`, unique pair.

---

## 5. Documents (versioned, private)

**stored_files**: one row per physical file. Shared by every module.
| column | notes |
|---|---|
| uuid 🔑 | public identifier. The path is never exposed |
| disk, path | private disk only |
| original_name, mime_type, size_bytes | |
| sha256 | integrity check and duplicate detection |
| is_encrypted | BOOL |
| uploaded_by FK users | |

**applicant_documents**: the version history for each document type.
| column | notes |
|---|---|
| applicant_profile_id FK | |
| document_type | ENUM('cv','nic','education_cert','professional_cert','driving_licence','police_clearance','photo','other') |
| stored_file_id FK | |
| version | INT. Increments per (applicant, type) |
| is_current | BOOL. Only one current version per type, except certificates, which can have several |
| status | ENUM('pending_verification','verified','rejected','superseded') |
| reviewed_by FK users, reviewed_at, rejection_reason | |
| superseded_by_id FK self NULL | |

Uploading a new version marks the previous one `superseded`. **The old row and file are never deleted.**

**organization_documents** and **service_request_attachments** have the same structure, each pointing to `stored_files`.

---

## 6. Police clearance (critical)

**police_clearance_records**: one row per certificate. The full history is kept.
| column | notes |
|---|---|
| applicant_profile_id FK | |
| applicant_document_id FK | the uploaded file (versioned) |
| certificate_number 🔒 NULL | |
| issue_date | entered by the applicant and confirmed by an admin |
| issuing_authority | e.g. Police Headquarters / Divisional office |
| uploaded_at | |
| verification_status | ENUM('pending_verification','approved','rejected') |
| valid_until | DATE NULL. **Set by the admin when approving.** Defaults to issue_date + `pcc.default_validity_months`, and the admin can change it |
| next_renewal_due | DATE NULL. Normally equal to valid_until. Stored separately so admins can set an earlier renewal deadline |
| verified_by FK users, verified_at, rejection_reason, admin_notes | |
| superseded_at NULL | set when a newer record is approved |

**The displayed status is calculated, not stored**, so it can never go stale:
```
rejected                → Rejected
pending_verification    → Pending Verification
approved & today > valid_until                        → Expired
approved & today ≥ valid_until − expiring_soon_days   → Expiring Soon
approved                → Valid
```
An uploaded certificate is **never** treated as valid until `verification_status = approved`.

**police_clearance_reminders**: `police_clearance_record_id FK`, `reminder_type ENUM('d30','d14','d7','expired')`, `sent_at`, unique(record, type). The unique key makes the daily job idempotent, so the same reminder is never sent twice.

---

## 7. Recruitment

**job_vacancies**: `job_category_id FK`, title, slug 🔑, description (sanitised rich text), requirements, `district_id FK NULL`, location_text, employment_type ENUM('full_time','contract','project','daily'), positions, min_experience_years, `requires_police_clearance BOOL default 1`, closing_date, `status ENUM('draft','published','closed','archived')`, created_by.
**job_vacancy_districts**: for vacancies that cover several locations.

**job_applications**: `job_vacancy_id FK`, `applicant_profile_id FK`, unique(vacancy, applicant), cover_note, `cv_document_id FK` (the CV version used at the time of applying), `status ENUM('submitted','under_review','shortlisted','interview_scheduled','selected','rejected','on_hold')`, submitted_at.
**job_application_status_history**: application_id, from_status, to_status, changed_by, note, created_at (append-only).

**interviews**: `job_application_id FK`, scheduled_at, `mode ENUM('in_person','phone','video')`, location_or_link, panel (JSON of staff ids), `status ENUM('scheduled','completed','no_show','cancelled','rescheduled')`, outcome, score, notes.

---

## 8. Organisations & service requests

**organizations**: name, registration_number NULL, `organization_type ENUM('government','private','semi_government','ngo','other')`, address, billing_address, email, phone, `status ENUM('pending','active','suspended')`, verified_by, verified_at.
**organization_users**: `organization_id FK`, `user_id FK`, designation, `is_primary`. An organisation can have more than one login.

**service_requests**
| column | notes |
|---|---|
| reference 🔑 | e.g. `SR-2026-00042` |
| organization_id FK, submitted_by FK users | |
| service_category_id FK | |
| title, description | |
| required_headcount | INT |
| required_qualifications, required_experience_years | |
| required_skills | via pivot `service_request_skills` (skill_id, min_years) |
| district_id FK, location_text | |
| start_date, expected_duration_value + unit ENUM('days','weeks','months') | |
| working_hours, special_requirements | |
| requires_police_clearance | BOOL default 1 |
| budget_amount DECIMAL(14,2) NULL, budget_currency CHAR(3) 'LKR' | |
| status | ENUM('submitted','under_review','quotation_preparation','quotation_sent','approved','team_assigned','in_progress','completed','cancelled') |
| assigned_manager_id FK users NULL | |
| show_team_to_client | BOOL. Controls whether the client can see the assigned team |
| completed_at, cancelled_at, cancel_reason | |

**service_request_status_history**: same pattern as job applications.
**service_request_messages**: request_id, sender_id, body, `is_internal BOOL` (staff-only notes), attachments via stored_files pivot, read_at.

**quotations**: `service_request_id FK`, reference 🔑, version, `status ENUM('draft','sent','accepted','rejected','expired','superseded')`, subtotal, tax_amount, total, currency, valid_until, terms, `pdf_file_id FK`, sent_at, responded_at, responded_by, client_comment, prepared_by, approved_by.
**quotation_items**: quotation_id, description, quantity, unit, unit_price, line_total, sort.

---

## 9. Workforce & staff roles

**workforce_assignments**
| column | notes |
|---|---|
| service_request_id FK, applicant_profile_id FK | unique(request, applicant) while active |
| role_title | e.g. "Tower Rigger", "Site Engineer" |
| start_date, end_date NULL | |
| status | ENUM('proposed','confirmed','active','completed','released','cancelled') |
| police_clearance_record_id FK NULL | **a snapshot of the certificate that was valid when the assignment was made** |
| assigned_by FK users, notes | |

**Enforced rule (in the `AssignApplicantToRequest` action and in the matching query):** if `service_requests.requires_police_clearance = 1`, the applicant must:
- be `verification_status = verified`,
- not be blacklisted, and
- have an approved police clearance certificate whose `valid_until ≥` the assignment end date (or start date + duration).

If any condition fails, the assignment is refused with a stated reason. Only a Super Admin can override, and the override is audit-logged with a mandatory justification.

**Proposed staff roles** (permissions are listed in the implementation plan):
| Role | Scope |
|---|---|
| Super Admin | everything, including user and role management and settings |
| HR Manager | applicants, vacancies, applications, interviews, reports |
| Recruiter | applicants (no police certificate files), applications, interviews |
| Compliance Officer | document and **police clearance verification**, renewal history |
| Operations Manager | organisations, service requests, matching, assignments |
| Finance / Quotations | quotations, organisation billing details |
| Auditor (read-only) | reports and audit logs |

---

## 10. Notifications & audit

- **notifications**: Laravel's built-in table for in-app notifications (bell icon on the dashboards).
- **notification_templates**: `key 🔑` (e.g. `pcc.reminder.d30`), channel ENUM('mail','sms','whatsapp'), subject, body (Markdown with `{{placeholders}}`), locale, is_enabled. **Admins can edit these**, which makes the system configurable.
- **notification_logs**: user_id, template_key, channel, recipient, `status ENUM('queued','sent','failed')`, error, provider_message_id, sent_at.
- **notification_preferences**: user_id, template_key, channel, enabled. Mandatory notices such as renewal and security emails cannot be switched off.
- **audit_logs** (append-only, no updated_at): actor_id NULL, actor_type, action (e.g. `pcc.approved`, `document.downloaded`, `user.deactivated`, `login.failed`), auditable_type, auditable_id, old_values JSON, new_values JSON (sensitive fields redacted), ip, user_agent, created_at. Indexed on (auditable_type, auditable_id) and (actor_id, created_at).

---

## 11. Key indexes for matching and reports
- `applicant_profiles (verification_status, applicant_status, availability_status, district_id)`
- `applicant_skills (skill_id, years)`
- `applicant_job_categories (job_category_id)`
- `police_clearance_records (applicant_profile_id, verification_status, valid_until)`
- `police_clearance_records (verification_status, valid_until)` for the daily reminder scan
- `job_applications (status, job_vacancy_id)`, `service_requests (status, service_category_id)`

## 12. Data retention (draft, needs legal confirmation)
| Data | Proposed retention |
|---|---|
| Active applicant profile | while the account is active, plus 2 years of inactivity, then the applicant is asked to renew or be anonymised |
| Police clearance certificates | all versions kept while the applicant is engaged, plus 7 years after the last assignment (never auto-deleted; deletion needs admin approval) |
| Rejected or unsuccessful applications | 2 years |
| Audit logs | 7 years |
| Service requests and quotations | 7 years (financial records) |
