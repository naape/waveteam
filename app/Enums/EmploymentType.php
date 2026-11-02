<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EmploymentType: string implements HasLabel, HasColor
{
    case FullTime = 'full_time';
    case Contract = 'contract';
    case Project = 'project';
    case Daily = 'daily';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::FullTime => 'Full Time',
            self::Contract => 'Contract',
            self::Project => 'Project Based',
            self::Daily => 'Daily Wage',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::FullTime => 'gray',
            self::Contract => 'gray',
            self::Project => 'gray',
            self::Daily => 'gray',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
