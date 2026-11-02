<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DocumentType: string implements HasLabel, HasColor
{
    case Cv = 'cv';
    case Nic = 'nic';
    case EducationCert = 'education_cert';
    case ProfessionalCert = 'professional_cert';
    case DrivingLicence = 'driving_licence';
    case PoliceClearance = 'police_clearance';
    case Photo = 'photo';
    case Other = 'other';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Cv => 'CV / Resume',
            self::Nic => 'NIC Copy',
            self::EducationCert => 'Educational Certificate',
            self::ProfessionalCert => 'Professional Certificate',
            self::DrivingLicence => 'Driving Licence',
            self::PoliceClearance => 'Police Clearance Certificate',
            self::Photo => 'Profile Photo',
            self::Other => 'Other',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Cv => 'gray',
            self::Nic => 'gray',
            self::EducationCert => 'gray',
            self::ProfessionalCert => 'gray',
            self::DrivingLicence => 'gray',
            self::PoliceClearance => 'gray',
            self::Photo => 'gray',
            self::Other => 'gray',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
