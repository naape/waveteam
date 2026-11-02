<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InterviewMode: string implements HasLabel, HasColor
{
    case InPerson = 'in_person';
    case Phone = 'phone';
    case Video = 'video';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::InPerson => 'In Person',
            self::Phone => 'Phone',
            self::Video => 'Video Call',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::InPerson => 'gray',
            self::Phone => 'gray',
            self::Video => 'gray',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
