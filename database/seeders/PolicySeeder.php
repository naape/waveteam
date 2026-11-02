<?php

namespace Database\Seeders;

use App\Models\PolicyVersion;
use Illuminate\Database\Seeder;

/**
 * DRAFT policies (v0.1). These MUST be reviewed by a Sri Lankan lawyer before
 * go-live. A legal edit should be published as a NEW version (never edit a
 * version users have already consented to) — consent_records point to versions.
 */
class PolicySeeder extends Seeder
{
    private const DRAFT_NOTICE = "> **Draft for legal review.** This document has been prepared as a working draft and has not yet been approved by WaveTeam's legal advisers.\n\n";

    public function run(): void
    {
        $company = config('portal.company');

        $policies = [
            'privacy' => ['Privacy Policy', $this->privacy($company)],
            'terms' => ['Terms of Use', $this->terms($company)],
            'retention' => ['Data Retention Policy', $this->retention($company)],
        ];

        foreach ($policies as $type => [$title, $body]) {
            PolicyVersion::firstOrCreate(
                ['type' => $type, 'version' => '0.1-draft'],
                ['title' => $title, 'body' => self::DRAFT_NOTICE.$body, 'published_at' => now()],
            );
        }
    }

    private function privacy(array $c): string
    {
        return <<<MD
        **Effective:** on publication · **Version:** 0.1 (draft)

        {$c['name']} ("WaveTeam", "we") operates this portal to recruit technical personnel and to provide engineering services to organisations. This policy explains how we handle personal data in line with the **Personal Data Protection Act, No. 9 of 2022 of Sri Lanka** ("PDPA").

        ## 1. Who is responsible
        WaveTeam is the *controller* of the personal data processed through this portal.
        Contact: {$c['email']} · {$c['phone']} · {$c['address']}.

        ## 2. What we collect
        **Applicants:** name, NIC and passport numbers, date of birth, gender (optional), addresses, district, phone and WhatsApp numbers, email, emergency contact, photograph, education, qualifications, skills, employment history, languages, driving licence details, military service (optional), availability and preferred work locations, and copies of supporting documents.

        **Police clearance certificates:** these contain information about criminal records, which is a *special category* of personal data under the PDPA. We collect them only with your explicit consent and only because many of our clients' sites (including telecommunication and defence-related installations) require cleared personnel.

        **Organisations:** organisation details and the name, designation and contact details of authorised contact persons.

        **Technical data:** sign-in times, IP address, browser type and an audit record of actions taken in the portal, for security.

        ## 3. Why we use it (purposes and lawful basis)
        | Purpose | Lawful basis |
        |---|---|
        | Creating and securing your account | Performance of a contract / your request |
        | Assessing suitability for vacancies and assignments | Your consent; steps prior to a contract |
        | Verifying identity, qualifications and police clearance | Your explicit consent; legitimate interest in site safety; client contractual requirements |
        | Assigning personnel to client projects | Performance of a contract |
        | Sending service and renewal notifications | Performance of a contract; legitimate interest |
        | Preparing quotations and managing service requests | Performance of a contract |
        | Security, fraud prevention and audit | Legitimate interest; legal obligations |

        We do **not** sell personal data and do not use it for unrelated marketing.

        ## 4. Who can see it
        - Only authorised WaveTeam staff, according to their role. Police clearance certificates and full NIC numbers are restricted to specifically authorised staff.
        - **Clients:** when you are assigned to a project, the client may see your name, role, relevant qualifications and police clearance status (not the certificate itself), only where WaveTeam enables this.
        - Service providers who host the portal or send emails on our behalf, under confidentiality obligations.
        - Authorities, where required by law.

        ## 5. Transfers outside Sri Lanka
        If portal data is hosted or backed up outside Sri Lanka, we will do so only as permitted under the PDPA and with appropriate safeguards. *[Hosting location to be confirmed before go-live.]*

        ## 6. How long we keep it
        See our [Data Retention Policy](/data-retention). Historical police clearance certificates are kept as a compliance record and are not automatically deleted.

        ## 7. How we protect it
        Encryption of documents and identity numbers, role-based access control, multi-factor authentication for staff, audit logging, secure backups and HTTPS.

        ## 8. Your rights
        Subject to the PDPA, you may request access to, correction of, or erasure of your personal data; withdraw consent; and object to certain processing. Withdrawing consent for police clearance processing means we cannot assign you to projects that require it. Some records must be kept for legal or contractual reasons even after a deletion request. Contact {$c['email']}. You may also complain to the Data Protection Authority of Sri Lanka.

        ## 9. Changes
        We will publish new versions here and ask for renewed consent where required.
        MD;
    }

    private function terms(array $c): string
    {
        return <<<MD
        **Version:** 0.1 (draft)

        These terms govern your use of the WaveTeam Portal operated by {$c['name']}.

        ## 1. Accounts
        - You must provide accurate, complete and current information, and keep your password confidential.
        - One account per person. Organisation accounts must be created by a person authorised to act for that organisation.
        - Notify us at {$c['email']} if you suspect unauthorised use.

        ## 2. Applicants
        - Registering or applying does not guarantee employment, interviews or assignments.
        - Documents you upload must be genuine. **Submitting false or altered documents will result in account termination and may be reported to the relevant authorities.**
        - You must keep your police clearance certificate current to remain eligible for assignments that require it.

        ## 3. Organisations
        - Service requests are enquiries. A quotation becomes binding only when accepted through the portal and confirmed by WaveTeam.
        - Information about assigned personnel is confidential and may be used only for the relevant project.

        ## 4. Acceptable use
        Do not attempt to access other users' data, interfere with the portal's security, or upload malicious files.

        ## 5. Suspension
        WaveTeam may suspend or deactivate accounts that breach these terms or for security reasons.

        ## 6. Liability
        The portal is provided on an "as available" basis. To the extent permitted by law, WaveTeam is not liable for indirect losses arising from its use.

        ## 7. Governing law
        These terms are governed by the laws of the Democratic Socialist Republic of Sri Lanka.
        MD;
    }

    private function retention(array $c): string
    {
        return <<<MD
        **Version:** 0.1 (draft)

        We keep personal data only as long as necessary for the purposes described in our [Privacy Policy](/privacy-policy), or as required by law.

        | Data | Retention period |
        |---|---|
        | Active applicant profile and documents | While the account is active |
        | Inactive applicant accounts | 2 years after last activity; you will be asked to confirm before anonymisation |
        | Police clearance certificates (all versions) | While engaged with WaveTeam and 7 years after the last assignment. Never deleted automatically; deletion requires authorised approval |
        | Unsuccessful job applications | 2 years |
        | Organisation accounts, service requests and quotations | 7 years after the last transaction (financial records) |
        | Security and audit logs | 7 years |
        | Backups | Rolling 30 days |

        At the end of the retention period, data is securely deleted or irreversibly anonymised. Requests: {$c['email']}.
        MD;
    }
}
