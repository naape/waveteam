<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UserType: string implements HasLabel, HasColor
{
    case Applicant = 'applicant';
    case OrgContact = 'org_contact';
    case Staff = 'staff';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Applicant => 'Applicant',
            self::OrgContact => 'Organization Contact',
            self::Staff => 'WaveTeam Staff',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Applicant => 'info',
            self::OrgContact => 'warning',
            self::Staff => 'primary',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
