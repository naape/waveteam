<?php

namespace Database\Seeders;

use App\Models\District;
use App\Models\JobCategory;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\Skill;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Idempotent: safe to run repeatedly (updateOrCreate everywhere).
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->districts();
        $this->serviceCategories();
        $this->jobCategoriesAndSkills();
        $this->settings();
    }

    private function districts(): void
    {
        // [English, Sinhala, Tamil, Province] — Sinhala/Tamil to be reviewed by a native speaker.
        $districts = [
            ['Colombo', 'කොළඹ', 'கொழும்பு', 'Western'],
            ['Gampaha', 'ගම්පහ', 'கம்பஹா', 'Western'],
            ['Kalutara', 'කළුතර', 'களுத்துறை', 'Western'],
            ['Kandy', 'මහනුවර', 'கண்டி', 'Central'],
            ['Matale', 'මාතලේ', 'மாத்தளை', 'Central'],
            ['Nuwara Eliya', 'නුවරඑළිය', 'நுவரெலியா', 'Central'],
            ['Galle', 'ගාල්ල', 'காலி', 'Southern'],
            ['Matara', 'මාතර', 'மாத்தறை', 'Southern'],
            ['Hambantota', 'හම්බන්තොට', 'அம்பாந்தோட்டை', 'Southern'],
            ['Jaffna', 'යාපනය', 'யாழ்ப்பாணம்', 'Northern'],
            ['Kilinochchi', 'කිලිනොච්චිය', 'கிளிநொச்சி', 'Northern'],
            ['Mannar', 'මන්නාරම', 'மன்னார்', 'Northern'],
            ['Vavuniya', 'වවුනියාව', 'வவுனியா', 'Northern'],
            ['Mullaitivu', 'මුලතිව්', 'முல்லைத்தீவு', 'Northern'],
            ['Batticaloa', 'මඩකලපුව', 'மட்டக்களப்பு', 'Eastern'],
            ['Ampara', 'අම්පාර', 'அம்பாறை', 'Eastern'],
            ['Trincomalee', 'ත්‍රිකුණාමලය', 'திருகோணமலை', 'Eastern'],
            ['Kurunegala', 'කුරුණෑගල', 'குருணாகல்', 'North Western'],
            ['Puttalam', 'පුත්තලම', 'புத்தளம்', 'North Western'],
            ['Anuradhapura', 'අනුරාධපුරය', 'அனுராதபுரம்', 'North Central'],
            ['Polonnaruwa', 'පොළොන්නරුව', 'பொலன்னறுவை', 'North Central'],
            ['Badulla', 'බදුල්ල', 'பதுளை', 'Uva'],
            ['Monaragala', 'මොණරාගල', 'மொனராகலை', 'Uva'],
            ['Ratnapura', 'රත්නපුර', 'இரத்தினபுரி', 'Sabaragamuwa'],
            ['Kegalle', 'කෑගල්ල', 'கேகாலை', 'Sabaragamuwa'],
        ];

        foreach ($districts as [$en, $si, $ta, $province]) {
            District::updateOrCreate(['name_en' => $en], ['name_si' => $si, 'name_ta' => $ta, 'province' => $province]);
        }
    }

    private function serviceCategories(): void
    {
        $categories = [
            'Telecommunication Engineering',
            'Tower Construction and Maintenance',
            'Electrical and Electronic Maintenance',
            'Networking and Fiber Optic Services',
            'Generator Installation and Maintenance',
            'Lightning Protection and Earthing',
            'Crew and Equipment Transportation',
            'Technical Manpower Supply',
            'Engineering Consultancy',
            'Emergency Maintenance Services',
        ];

        foreach ($categories as $i => $name) {
            ServiceCategory::updateOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'sort' => $i]);
        }
    }

    private function jobCategoriesAndSkills(): void
    {
        $catalog = [
            'Telecommunication Engineering' => ['RF Planning & Optimisation', 'BTS / NodeB / eNodeB Installation', '5G NR Installation', 'Microwave Link Installation', 'Antenna Alignment', 'Site Acquisition & Survey', 'Drive Testing'],
            'Tower Construction and Maintenance' => ['Tower Climbing (Certified)', 'Tower Erection', 'Rigging', 'Tower Structural Maintenance', 'Working at Height', 'Painting & Corrosion Protection'],
            'Transmission Services' => ['Transmission Equipment Installation', 'SDH / DWDM Commissioning', 'Microwave Transmission'],
            'Electrical and Power' => ['Electrical Wiring (Industrial)', 'LV/HV Installation', 'DC Power Systems & Rectifiers', 'Battery Bank Maintenance', 'Solar PV Installation', 'Panel Board Assembly'],
            'Generator Services' => ['Generator Installation', 'Generator Servicing & Overhaul', 'ATS / AMF Panel Maintenance', 'Diesel Engine Mechanics'],
            'Lightning Protection and Earthing' => ['Lightning Protection Installation', 'Earthing System Installation', 'Earth Resistance Testing'],
            'Networking and Fiber Optic' => ['Fiber Splicing', 'OTDR Testing', 'FTTH Installation', 'Structured Cabling', 'Network Configuration (Routers/Switches)'],
            'Drivers and Transportation' => ['Heavy Vehicle Driving', 'Light Vehicle Driving', 'Crane Operation', 'Boom Truck Operation'],
            'Civil Works' => ['Foundation & Concrete Works', 'Masonry', 'Steel Fixing', 'Site Supervision (Civil)'],
            'Engineering and Supervision' => ['Project Management', 'Site Engineering', 'Health, Safety & Environment (HSE)', 'AutoCAD Drafting', 'Quality Assurance'],
            'General Technical Support' => ['General Technician', 'Welding', 'Plumbing', 'Store Keeping'],
        ];

        $sort = 0;
        foreach ($catalog as $category => $skills) {
            $cat = JobCategory::updateOrCreate(['slug' => Str::slug($category)], ['name' => $category, 'sort' => $sort++]);

            foreach ($skills as $skill) {
                Skill::updateOrCreate(['name' => $skill], ['job_category_id' => $cat->id]);
            }
        }
    }

    private function settings(): void
    {
        $descriptions = [
            'pcc.default_validity_months' => 'Default police clearance validity (months from issue date). Admin can override per certificate.',
            'pcc.reminder_days' => 'Days before renewal due date on which reminder emails are sent.',
            'pcc.expiring_soon_days' => 'Window (days) in which a valid certificate is shown as "Expiring Soon".',
            'uploads.max_kb' => 'Maximum upload size per file (KB).',
            'uploads.allowed_mimes' => 'Allowed upload file extensions.',
            'matching.weights' => 'Workforce matching score weights (percent).',
            'session.staff_idle_minutes' => 'Staff session idle timeout (minutes).',
        ];

        foreach (config('portal.defaults') as $key => $value) {
            // Never overwrite a value an administrator has already changed.
            Setting::firstOrCreate(['key' => $key], ['value' => $value, 'description' => $descriptions[$key] ?? null]);
        }
    }
}
