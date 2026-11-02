<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PccVerificationStatus: string implements HasLabel, HasColor
{
    case PendingVerification = 'pending_verification';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::PendingVerification => 'Pending Verification',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PendingVerification => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
