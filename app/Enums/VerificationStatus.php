<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VerificationStatus: string implements HasLabel, HasColor
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Unverified => 'Unverified',
            self::Pending => 'Pending Verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unverified => 'gray',
            self::Pending => 'warning',
            self::Verified => 'success',
            self::Rejected => 'danger',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
