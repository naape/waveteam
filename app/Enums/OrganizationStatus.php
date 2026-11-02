<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrganizationStatus: string implements HasLabel, HasColor
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Pending => 'Pending Approval',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active => 'success',
            self::Suspended => 'danger',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
