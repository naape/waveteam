<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Default email templates (editable by admins). Existing edits are preserved.
 * Placeholders use {{name}} syntax and are rendered by the notification layer.
 */
class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            ['applicant.registered', 'Applicant registration confirmation', 'Welcome to WaveTeam, {{name}}', "Thank you for registering with WaveTeam Engineering.\n\nPlease complete your profile and upload your documents so our team can review your application.", false],
            ['organization.registered', 'Organisation registration confirmation', 'Welcome to WaveTeam, {{organization_name}}', "Thank you for registering **{{organization_name}}** with WaveTeam Engineering.\n\nOur team will review your organisation details. You can already prepare and submit service requests from your dashboard.", false],
            ['account.verify_email', 'Account verification', 'Verify your WaveTeam account', "Please confirm your email address to activate your account.\n\n{{action_url}}", true],
            ['application.submitted', 'Job application confirmation', 'Application received: {{vacancy_title}}', "We have received your application for **{{vacancy_title}}**. You can track its progress from your dashboard.", false],
            ['application.status_changed', 'Application status update', 'Update on your application: {{vacancy_title}}', "The status of your application for **{{vacancy_title}}** is now **{{status}}**.", false],
            ['interview.invitation', 'Interview invitation', 'Interview invitation: {{vacancy_title}}', "You are invited to an interview for **{{vacancy_title}}**.\n\nDate & time: {{scheduled_at}}\nMode: {{mode}}\nLocation / link: {{location}}", false],
            ['document.rejected', 'Document rejected', 'Action required: {{document}}', "Your {{document}} could not be verified.\n\nReason: {{reason}}\n\nPlease upload a corrected document from your dashboard.", true],
            ['profile.verified', 'Profile verified', 'Your WaveTeam profile has been verified', "Good news — WaveTeam has verified your profile. You are now eligible to be considered for assignments.", false],
            ['organization.status_changed', 'Organisation status update', 'Your organisation account: {{status}}', "The status of **{{organization_name}}** on the WaveTeam portal is now **{{status}}**.\n\n{{note}}", true],
            ['pcc.uploaded.admin', 'New police clearance uploaded (admin)', 'Police clearance awaiting verification: {{applicant_name}}', "{{applicant_name}} uploaded a new police clearance certificate. Please verify it in the admin portal.", true],
            ['pcc.approved', 'Police clearance approved', 'Your police clearance certificate has been verified', "Your police clearance certificate has been verified. Next renewal due: **{{renewal_date}}**.", true],
            ['pcc.rejected', 'Police clearance rejected', 'Action required: police clearance certificate', "Your police clearance certificate could not be verified.\n\nReason: {{reason}}\n\nPlease upload a valid certificate.", true],
            ['pcc.reminder', 'Police clearance renewal reminder', 'Your police clearance renewal is due in {{days}} days', "Your police clearance certificate must be renewed by **{{renewal_date}}**. Please upload your new certificate before this date to remain eligible for assignments.", true],
            ['pcc.expired', 'Expired document notification', 'Your police clearance certificate has expired', "Your police clearance certificate expired on **{{renewal_date}}**. You are not eligible for assignments that require police clearance until a new certificate is verified.", true],
            ['service_request.submitted', 'Service request confirmation', 'Service request received: {{reference}}', "Thank you. We have received your service request **{{reference}} – {{title}}**. Our team will review it shortly.", false],
            ['assignment.status_changed', 'Assignment update', 'Assignment update: {{project_title}}', "Your assignment as **{{role_title}}** on **{{project_title}}** is now **{{status}}**.\n\n{{note}}", false],
            ['report.ready', 'Report export ready (staff)', 'Your report is ready: {{report}}', "The **{{report}}** export you requested ({{rows}} rows, {{format}}) is ready to download. The link works only for your account.", true],
            ['pcc.ops_digest', 'Weekly police clearance risk digest (admin)', 'Police clearance: {{count}} assignment(s) at risk', "The following people are assigned to sites that require police clearance, but their certificate is expired, missing or due before the assignment ends:\n\n{{list}}\n\nPlease follow up with them or plan replacements.", true],
            ['service_request.submitted.admin', 'New service request (admin)', 'New service request {{reference}} from {{organization_name}}', "{{organization_name}} submitted a new service request: **{{title}}** ({{category}}).\n\nOrganisation status: {{organization_status}}.", true],
            ['service_request.status_changed', 'Service request status update', 'Update on {{reference}}: {{status}}', "The status of your service request **{{reference}} – {{title}}** is now **{{status}}**.", false],
            ['service_request.cancelled.admin', 'Service request cancelled by client (admin)', '{{reference}} cancelled by {{organization_name}}', "{{organization_name}} cancelled service request **{{reference}} – {{title}}**.\n\nReason: {{reason}}", true],
            ['service_request.message', 'New message from WaveTeam', 'New message about {{reference}}', "WaveTeam sent you a message about **{{reference}} – {{title}}**. Sign in to read and reply.", false],
            ['service_request.message.admin', 'New client message (admin)', 'New message from {{organization_name}} on {{reference}}', "{{sender}} ({{organization_name}}) wrote about **{{reference}} – {{title}}**:\n\n{{excerpt}}", true],
            ['quotation.responded.admin', 'Quotation accepted/rejected (admin)', 'Quotation {{quotation_reference}} {{decision}} by {{organization_name}}', "{{organization_name}} has **{{decision}}** quotation {{quotation_reference}} for **{{reference}}**.\n\nComment: {{comment}}", true],
            ['quotation.sent', 'Quotation notification', 'Quotation {{quotation_reference}} for {{reference}}', "A quotation for your service request **{{reference}}** is ready. Please review and accept or reject it from your dashboard.", false],
            ['assignment.created', 'Service assignment notification', 'New work assignment: {{project_title}}', "You have been assigned as **{{role_title}}** on **{{project_title}}** starting **{{start_date}}**. Please check your dashboard for details.", false],
        ];

        // SMS versions (short, plain text). Disabled until an SMS provider is contracted.
        $sms = [
            ['pcc.reminder', 'Police clearance reminder (SMS)', 'WaveTeam: your police clearance must be renewed by {{renewal_date}}. Please upload the new certificate on the portal.'],
            ['pcc.expired', 'Police clearance expired (SMS)', 'WaveTeam: your police clearance expired on {{renewal_date}}. Upload a new certificate to stay eligible for site work.'],
            ['interview.invitation', 'Interview invitation (SMS)', 'WaveTeam: interview for {{vacancy_title}} on {{scheduled_at}} ({{mode}}). Details on the portal.'],
            ['assignment.created', 'Assignment (SMS)', 'WaveTeam: you are assigned as {{role_title}} on {{project_title}} from {{start_date}}. Check the portal.'],
        ];
        foreach ($sms as [$key, $name, $body]) {
            NotificationTemplate::firstOrCreate(
                ['key' => $key, 'channel' => 'sms', 'locale' => 'en'],
                ['name' => $name, 'subject' => null, 'body' => $body, 'is_mandatory' => false, 'is_enabled' => false],
            );
        }

        foreach ($templates as [$key, $name, $subject, $body, $mandatory]) {
            NotificationTemplate::firstOrCreate(
                ['key' => $key, 'channel' => 'mail', 'locale' => 'en'],
                ['name' => $name, 'subject' => $subject, 'body' => $body, 'is_mandatory' => $mandatory, 'is_enabled' => true],
            );
        }
    }
}
