<x-portal-landing
    :portal="\App\Enums\Portal::Applicant"
    :eyebrow="__('Applicant Portal')"
    :title="__('Technical careers with WaveTeam Engineering')"
    :intro="__('Telecom riggers, engineers, electricians, fibre technicians, drivers and more — register once, keep your documents current and apply for work across Sri Lanka.')"
    :register-label="__('Create applicant account')"
    :steps="[
        [__('Create your account'), __('Register with your NIC, mobile number and email, then verify your email address.')],
        [__('Complete your profile'), __('Add qualifications, skills and experience, and upload your CV, certificates and police clearance.')],
        [__('Apply and get verified'), __('Apply for vacancies and track progress. Verified applicants can be assigned to projects.')],
    ]"
    :features="[
        ['heroicon-o-briefcase', __('Vacancies across the island'), __('Search roles by category and district and set your preferred working locations.')],
        ['heroicon-o-document-check', __('One secure document wallet'), __('Your NIC, certificates and police clearance are encrypted and visible only to authorised staff.')],
        ['heroicon-o-bell-alert', __('Renewal reminders'), __('We remind you 30, 14 and 7 days before your police clearance renewal is due.')],
    ]"
/>
