<?php

namespace Database\Seeders;

use App\Enums\EmploymentType;
use App\Enums\VacancyStatus;
use App\Models\District;
use App\Models\JobCategory;
use App\Models\JobVacancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * DEMO DATA for local development / UAT only — never run in production.
 *   php artisan db:seed --class=DemoVacancySeeder
 * Vacancy management by staff arrives with the admin dashboard (Phase 6).
 */
class DemoVacancySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Refusing to seed demo vacancies in production.');

            return;
        }

        $d = fn (string $name) => District::where('name_en', $name)->value('id');
        $c = fn (string $slug) => JobCategory::where('slug', $slug)->value('id');

        $vacancies = [
            ['tower-construction-and-maintenance', 'Certified Tower Rigger', 'Gampaha', 'Gampaha & Western Province sites', EmploymentType::Contract, 6, 2,
                "Install and maintain antennas, RRUs and microwave dishes on telecom towers up to 80 m.\nWork in teams of 3–4 under a site supervisor.\nTravel within the Western Province; transport provided from Gampaha.",
                "Valid tower climbing / working at height certification\nMinimum 2 years of rigging experience\nPhysically fit; no fear of heights"],
            ['telecommunication-engineering', 'RF / Transmission Technician', 'Colombo', 'Colombo', EmploymentType::FullTime, 2, 3,
                "Commissioning and troubleshooting of BTS, NodeB and eNodeB equipment and microwave links for a major mobile operator.",
                "NVQ Level 4 or Diploma in Electronics / Telecommunication\n3+ years in telecom field work\nLaptop and basic CLI skills"],
            ['networking-and-fiber-optic', 'Fibre Splicing Technician', 'Kandy', 'Kandy & Central Province', EmploymentType::Project, 4, 1,
                "FTTH and backbone fibre splicing, OTDR testing and documentation for a 6-month rollout project.",
                "Hands-on fusion splicing experience\nOTDR testing knowledge is an advantage"],
            ['generator-services', 'Generator Mechanic', 'Kurunegala', 'North Western Province (mobile)', EmploymentType::Contract, 2, 3,
                "Preventive and corrective maintenance of standby generators (20–200 kVA) at telecom sites.",
                "Diesel engine mechanics experience\nATS / AMF panel knowledge\nValid driving licence (B1/B)"],
            ['drivers-and-transportation', 'Heavy Vehicle Driver (Crew Transport)', 'Gampaha', 'Island-wide', EmploymentType::FullTime, 3, 5,
                "Transport technical crews and equipment to sites island-wide. Overnight travel required.",
                "Heavy vehicle licence (Class C/CE)\n5+ years of clean driving record"],
            ['lightning-protection-and-earthing', 'Earthing & Lightning Protection Technician', 'Galle', 'Southern Province', EmploymentType::Daily, 4, 1,
                "Installation of earthing systems and lightning protection at telecom and industrial sites.",
                "Experience with earth resistance testing is an advantage"],
        ];

        foreach ($vacancies as [$cat, $title, $district, $location, $type, $positions, $years, $description, $requirements]) {
            $vacancy = JobVacancy::firstOrNew(['slug' => Str::slug($title)]);
            $vacancy->fill([
                'job_category_id' => $c($cat),
                'title' => $title,
                'description' => $description,
                'requirements' => $requirements,
                'district_id' => $d($district),
                'location_text' => $location,
                'employment_type' => $type,
                'positions' => $positions,
                'min_experience_years' => $years,
                'requires_police_clearance' => true,
                'closing_date' => now()->addWeeks(4)->toDateString(),
                'status' => VacancyStatus::Published,
                'published_at' => now()->subDays(rand(0, 10)),
            ])->save();
        }

        $this->command?->info(count($vacancies).' demo vacancies published.');
    }
}
