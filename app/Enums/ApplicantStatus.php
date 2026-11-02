<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ApplicantStatus: string implements HasLabel, HasColor
{
    case Active = 'active';
    case OnHold = 'on_hold';
    case Blacklisted = 'blacklisted';
    case Inactive = 'inactive';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Active => 'Active',
            self::OnHold => 'On Hold',
            self::Blacklisted => 'Blacklisted',
            self::Inactive => 'Inactive',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::OnHold => 'warning',
            self::Blacklisted => 'danger',
            self::Inactive => 'gray',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
