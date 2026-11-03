<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff roles & permissions. Idempotent. Role/permission names are referenced
 * by Policies, so changes here should be made through new migrations/seeders.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const SUPER_ADMIN = 'Super Admin';

    public const PERMISSIONS = [
        // Applicants
        'applicants.view', 'applicants.update', 'applicants.verify', 'applicants.change_status', 'applicants.export',
        'applicants.view_pii',          // full NIC / passport numbers (otherwise masked)
        // Documents & police clearance
        'documents.view', 'documents.verify',
        'police_clearance.view',        // open/download PCC files — sensitive
        'police_clearance.verify',
        // Recruitment
        'vacancies.manage', 'applications.view', 'applications.manage', 'interviews.manage',
        // Clients & services
        'organizations.view', 'organizations.manage', 'organizations.verify',
        'service_requests.view', 'service_requests.manage', 'service_requests.delete',
        'quotations.view', 'quotations.manage', 'quotations.approve', 'quotations.delete',
        // Workforce
        'workforce.match', 'workforce.assign', 'workforce.override_pcc',
        // Reports
        'reports.view', 'reports.export',
        // Administration
        'notifications.manage', 'staff.manage', 'roles.manage', 'audit_logs.view', 'settings.manage',
    ];

    public const ROLES = [
        'HR Manager' => [
            'applicants.view', 'applicants.update', 'applicants.verify', 'applicants.change_status', 'applicants.export', 'applicants.view_pii',
            'documents.view', 'documents.verify', 'police_clearance.view',
            'vacancies.manage', 'applications.view', 'applications.manage', 'interviews.manage',
            'workforce.match', 'reports.view', 'reports.export',
        ],
        'Recruiter' => [
            'applicants.view', 'applicants.update', 'documents.view',
            'vacancies.manage', 'applications.view', 'applications.manage', 'interviews.manage',
        ],
        'Compliance Officer' => [
            'applicants.view', 'applicants.verify', 'applicants.view_pii',
            'documents.view', 'documents.verify', 'police_clearance.view', 'police_clearance.verify',
            'reports.view', 'reports.export',
        ],
        'Operations Manager' => [
            'applicants.view', 'organizations.view', 'organizations.manage', 'organizations.verify',
            'service_requests.view', 'service_requests.manage', 'quotations.view',
            'workforce.match', 'workforce.assign', 'reports.view', 'reports.export',
        ],
        'Finance' => [
            'organizations.view', 'service_requests.view', 'quotations.view', 'quotations.manage', 'quotations.approve', 'reports.view',
        ],
        'Auditor' => [
            'applicants.view', 'applications.view', 'organizations.view', 'service_requests.view', 'quotations.view',
            'reports.view', 'reports.export', 'audit_logs.view',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::findOrCreate(self::SUPER_ADMIN, 'web')->syncPermissions(self::PERMISSIONS);

        foreach (self::ROLES as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
