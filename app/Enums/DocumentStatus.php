<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DocumentStatus: string implements HasLabel, HasColor
{
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::PendingVerification => 'Pending Verification',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Superseded => 'Superseded',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PendingVerification => 'warning',
            self::Verified => 'success',
            self::Rejected => 'danger',
            self::Superseded => 'gray',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
