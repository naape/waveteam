<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PoliceClearanceStatus: string implements HasLabel, HasColor
{
    case Valid = 'valid';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';
    case PendingVerification = 'pending_verification';
    case Rejected = 'rejected';
    case Missing = 'missing';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Valid => 'Valid',
            self::ExpiringSoon => 'Expiring Soon',
            self::Expired => 'Expired',
            self::PendingVerification => 'Pending Verification',
            self::Rejected => 'Rejected',
            self::Missing => 'Not Submitted',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Valid => 'success',
            self::ExpiringSoon => 'warning',
            self::Expired => 'danger',
            self::PendingVerification => 'info',
            self::Rejected => 'danger',
            self::Missing => 'gray',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
