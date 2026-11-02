<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrganizationType: string implements HasLabel, HasColor
{
    case Government = 'government';
    case SemiGovernment = 'semi_government';
    case Private = 'private';
    case Ngo = 'ngo';
    case Other = 'other';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Government => 'Government',
            self::SemiGovernment => 'Semi-Government',
            self::Private => 'Private Company',
            self::Ngo => 'NGO / Non-Profit',
            self::Other => 'Other',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Government => 'gray',
            self::SemiGovernment => 'gray',
            self::Private => 'gray',
            self::Ngo => 'gray',
            self::Other => 'gray',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
