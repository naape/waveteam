<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AssignmentStatus: string implements HasLabel, HasColor
{
    case Proposed = 'proposed';
    case Confirmed = 'confirmed';
    case Active = 'active';
    case Completed = 'completed';
    case Released = 'released';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Proposed => 'Proposed',
            self::Confirmed => 'Confirmed',
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Released => 'Released',
            self::Cancelled => 'Cancelled',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Proposed => 'gray',
            self::Confirmed => 'info',
            self::Active => 'primary',
            self::Completed => 'success',
            self::Released => 'warning',
            self::Cancelled => 'danger',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
