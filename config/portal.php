<?php

return [

    /*
    | Key for HMAC blind indexes (NIC look-ups). Must differ from APP_KEY.
    | Generate with: php -r "echo bin2hex(random_bytes(32));"
    */
    'blind_index_key' => env('BLIND_INDEX_KEY'),

    /*
    | Key for encrypting uploaded documents at rest (libsodium secretstream).
    | Separate from APP_KEY. Format "base64:<32 bytes>". LOSING IT MAKES ALL
    | DOCUMENTS UNREADABLE — back it up with the database backups' keys.
    */
    'document_key' => env('DOCUMENT_ENCRYPTION_KEY'),

    'admin_path' => env('ADMIN_PATH', 'admin'),

    // Comma-separated IPs / CIDRs; empty = no restriction.
    'admin_ip_allowlist' => array_filter(array_map('trim', explode(',', (string) env('ADMIN_IP_ALLOWLIST', '')))),

    // Disk for all private uploads. Never a public disk.
    'documents_disk' => env('DOCUMENTS_DISK', 'documents'),

    /*
    | Extra notification channels. Disabled until a provider is contracted;
    | the "log" driver writes messages to the application log.
    */
    'channels' => [
        'sms' => ['enabled' => (bool) env('SMS_ENABLED', false)],
        'whatsapp' => ['enabled' => (bool) env('WHATSAPP_ENABLED', false)],
        'driver' => env('TEXT_MESSAGE_DRIVER', 'log'),
    ],

    /*
    | Which applicant job categories can deliver each service category
    | (service category slug => job category slugs). Used by workforce
    | matching as a hard filter; an empty list means "any category".
    */
    'service_category_job_categories' => [
        'telecommunication-engineering' => ['telecommunication-engineering', 'transmission-services', 'tower-construction-and-maintenance'],
        'tower-construction-and-maintenance' => ['tower-construction-and-maintenance', 'civil-works'],
        'electrical-and-electronic-maintenance' => ['electrical-and-power', 'general-technical-support'],
        'networking-and-fiber-optic-services' => ['networking-and-fiber-optic', 'telecommunication-engineering'],
        'generator-installation-and-maintenance' => ['generator-services', 'electrical-and-power'],
        'lightning-protection-and-earthing' => ['lightning-protection-and-earthing', 'electrical-and-power'],
        'crew-and-equipment-transportation' => ['drivers-and-transportation'],
        'technical-manpower-supply' => [],
        'engineering-consultancy' => ['engineering-and-supervision'],
        'emergency-maintenance-services' => ['electrical-and-power', 'generator-services', 'general-technical-support', 'tower-construction-and-maintenance'],
    ],

    'reports' => [
        'inline_limit' => (int) env('REPORT_INLINE_LIMIT', 2000),
        'pdf_limit' => (int) env('REPORT_PDF_LIMIT', 5000),
    ],

    'company' => [
        'name' => 'WaveTeam Engineering',
        'website' => 'https://www.waveteam.lk',
        'email' => 'info@waveteam.lk',
        'phone' => '+94 77 315 6769',
        'address' => 'Church Road, Indigolla, Gampaha, Sri Lanka',
    ],

    /*
    | Defaults for admin-configurable settings (overridden by the `settings` table).
    */
    'defaults' => [
        'pcc.default_validity_months' => 12,
        'pcc.reminder_days' => [30, 14, 7],
        'pcc.expiring_soon_days' => 30,
        'uploads.max_kb' => 5120,
        'uploads.allowed_mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
        'matching.weights' => ['skills' => 40, 'experience' => 25, 'qualifications' => 15, 'location' => 15, 'completeness' => 5],
        'session.staff_idle_minutes' => 30,
    ],
];
