<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InterviewStatus: string implements HasLabel, HasColor
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Scheduled => 'Scheduled',
            self::Completed => 'Completed',
            self::NoShow => 'No Show',
            self::Cancelled => 'Cancelled',
            self::Rescheduled => 'Rescheduled',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Scheduled => 'info',
            self::Completed => 'success',
            self::NoShow => 'danger',
            self::Cancelled => 'gray',
            self::Rescheduled => 'warning',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
