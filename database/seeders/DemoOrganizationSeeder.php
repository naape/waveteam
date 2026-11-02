<?php

namespace Database\Seeders;

use App\Actions\ServiceRequests\ChangeServiceRequestStatus;
use App\Enums\AccountStatus;
use App\Enums\AssignmentStatus;
use App\Enums\OrganizationStatus;
use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserType;
use App\Models\ApplicantProfile;
use App\Models\District;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestMessage;
use App\Models\ServiceRequestStatusHistory;
use App\Models\User;
use App\Models\WorkforceAssignment;
use Illuminate\Database\Seeder;

/**
 * DEMO DATA for local development / UAT only — never run in production.
 *   php artisan db:seed --class=DemoOrganizationSeeder
 *
 * Creates "Lanka Telecom Contractors" (sign in: kumari@ltc.lk / SecurePass123)
 * with three requests: one new, one with a quotation awaiting a decision,
 * and one completed with an assigned team. Staff-side tools to do all of this
 * arrive in Phases 6 and 8.
 */
class DemoOrganizationSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Refusing to seed demo data in production.');

            return;
        }

        if (User::where('email', 'kumari@ltc.lk')->exists()) {
            $this->command?->warn('Demo organisation already exists.');

            return;
        }

        $staff = User::staff()->first();
        $cat = fn (string $slug) => ServiceCategory::where('slug', $slug)->value('id');
        $district = fn (string $name) => District::where('name_en', $name)->value('id');

        $org = new Organization([
            'name' => 'Lanka Telecom Contractors (Pvt) Ltd', 'registration_number' => 'PV 104233', 'organization_type' => 'private',
            'address' => '128 Galle Road, Colombo 03', 'billing_address' => '128 Galle Road, Colombo 03',
            'email' => 'projects@ltc.lk', 'phone' => '+94112345678',
        ]);
        $org->status = OrganizationStatus::Active;
        $org->verified_at = now()->subMonths(2);
        $org->save();

        $user = new User(['name' => 'Kumari Silva', 'email' => 'kumari@ltc.lk', 'password' => 'SecurePass123']);
        $user->user_type = UserType::OrgContact;
        $user->status = AccountStatus::Active;
        $user->email_verified_at = now();
        $user->save();
        OrganizationUser::create(['organization_id' => $org->id, 'user_id' => $user->id, 'designation' => 'Project Manager', 'is_primary' => true]);

        $make = function (array $attrs, ServiceRequestStatus $status, string $ago) use ($org, $user) {
            $r = new ServiceRequest($attrs + ['organization_id' => $org->id, 'submitted_by' => $user->id, 'requires_police_clearance' => true]);
            $r->status = ServiceRequestStatus::Submitted;
            $r->created_at = now()->sub($ago);
            $r->save();
            ServiceRequestStatusHistory::create(['service_request_id' => $r->id, 'to_status' => 'submitted', 'changed_by' => $user->id, 'created_at' => $r->created_at]);

            return $r;
        };
        $change = app(ChangeServiceRequestStatus::class);

        // 1. Newly submitted
        $make([
            'service_category_id' => $cat('lightning-protection-and-earthing'), 'title' => 'Earthing upgrade – 8 rooftop sites, Colombo',
            'description' => "Upgrade of earthing systems at 8 rooftop telecom sites in Colombo 1–7.\nEarth resistance must be below 5 ohms after works.",
            'required_headcount' => 4, 'required_experience_years' => 2, 'district_id' => $district('Colombo'), 'location_text' => 'Colombo 01–07 (8 sites)',
            'start_date' => now()->addWeeks(3), 'expected_duration_value' => 3, 'expected_duration_unit' => 'weeks', 'working_hours' => '08:00–17:00, Mon–Sat',
        ], ServiceRequestStatus::Submitted, '2 days');

        // 2. Quotation awaiting decision
        $r2 = $make([
            'service_category_id' => $cat('tower-construction-and-maintenance'), 'title' => 'Antenna swap at 12 sites – Western Province',
            'description' => "Swap of legacy 2G/3G antennas for multi-band antennas at 12 tower sites.\nIncludes RRU relocation and feeder clean-up.",
            'required_headcount' => 6, 'required_experience_years' => 3, 'required_qualifications' => 'Certified tower climbers; at least one supervisor with 5+ years.',
            'district_id' => $district('Gampaha'), 'location_text' => 'Gampaha & Kalutara districts (12 sites)', 'start_date' => now()->addWeeks(2),
            'expected_duration_value' => 6, 'expected_duration_unit' => 'weeks', 'working_hours' => '07:00–18:00', 'budget_amount' => 3500000,
        ], ServiceRequestStatus::Submitted, '9 days');
        $change($r2, ServiceRequestStatus::UnderReview, $staff);
        $change($r2, ServiceRequestStatus::QuotationPreparation, $staff);
        $q = new Quotation(['service_request_id' => $r2->id, 'reference' => 'QT-'.now()->year.'-0001', 'version' => 1, 'currency' => 'LKR',
            'valid_until' => now()->addDays(14), 'terms' => "50% mobilisation advance, balance on completion.\nPrices exclude VAT where applicable.", 'prepared_by' => $staff?->id]);
        $q->status = QuotationStatus::Sent;
        $q->sent_at = now()->subDay();
        $q->save();
        $lines = [['Tower rigging crew (6 persons) – per site', 12, 'site', 185000], ['Supervisor & HSE officer', 6, 'week', 95000], ['Crew & equipment transport', 1, 'lot', 240000]];
        $subtotal = 0;
        foreach ($lines as $i => [$desc, $qty, $unit, $price]) {
            QuotationItem::create(['quotation_id' => $q->id, 'description' => $desc, 'quantity' => $qty, 'unit' => $unit, 'unit_price' => $price, 'line_total' => $qty * $price, 'sort' => $i]);
            $subtotal += $qty * $price;
        }
        $q->forceFill(['subtotal' => $subtotal, 'tax_amount' => 0, 'total' => $subtotal])->save();
        $change($r2, ServiceRequestStatus::QuotationSent, $staff);
        ServiceRequestMessage::create(['service_request_id' => $r2->id, 'sender_id' => $staff?->id, 'is_internal' => false,
            'body' => "Dear Kumari,\nOur quotation for the 12-site antenna swap is now available below. Happy to walk through it on a call.", 'created_at' => now()->subDay()]);

        // 3. Completed with team
        $r3 = $make([
            'service_category_id' => $cat('generator-installation-and-maintenance'), 'title' => 'Quarterly generator servicing – 20 sites',
            'description' => 'Preventive maintenance of standby generators at 20 sites in the North Western Province.',
            'required_headcount' => 2, 'district_id' => $district('Kurunegala'), 'location_text' => 'Kurunegala & Puttalam',
            'start_date' => now()->subMonths(2), 'expected_duration_value' => 4, 'expected_duration_unit' => 'weeks',
        ], ServiceRequestStatus::Submitted, '3 months');
        foreach ([ServiceRequestStatus::UnderReview, ServiceRequestStatus::QuotationSent, ServiceRequestStatus::Approved, ServiceRequestStatus::TeamAssigned, ServiceRequestStatus::InProgress, ServiceRequestStatus::Completed] as $s) {
            $change($r3, $s, $staff);
        }
        $r3->forceFill(['show_team_to_client' => true])->save();
        if ($profile = ApplicantProfile::first()) {
            WorkforceAssignment::forceCreate(['service_request_id' => $r3->id, 'applicant_profile_id' => $profile->id, 'role_title' => 'Generator Technician',
                'start_date' => now()->subMonths(2), 'end_date' => now()->subMonth(), 'status' => AssignmentStatus::Completed, 'assigned_by' => $staff?->id]);
        }

        $this->command?->info('Demo organisation created: kumari@ltc.lk / SecurePass123');
    }
}
