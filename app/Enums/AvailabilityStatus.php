<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AvailabilityStatus: string implements HasLabel, HasColor
{
    case Available = 'available';
    case AvailableFrom = 'available_from';
    case Engaged = 'engaged';
    case Unavailable = 'unavailable';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Available => 'Available',
            self::AvailableFrom => 'Available From Date',
            self::Engaged => 'Currently Engaged',
            self::Unavailable => 'Unavailable',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::AvailableFrom => 'info',
            self::Engaged => 'warning',
            self::Unavailable => 'gray',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
