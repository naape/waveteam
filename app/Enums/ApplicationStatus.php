<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ApplicationStatus: string implements HasLabel, HasColor
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Shortlisted = 'shortlisted';
    case InterviewScheduled = 'interview_scheduled';
    case Selected = 'selected';
    case Rejected = 'rejected';
    case OnHold = 'on_hold';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under Review',
            self::Shortlisted => 'Shortlisted',
            self::InterviewScheduled => 'Interview Scheduled',
            self::Selected => 'Selected',
            self::Rejected => 'Rejected',
            self::OnHold => 'On Hold',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Submitted => 'gray',
            self::UnderReview => 'info',
            self::Shortlisted => 'primary',
            self::InterviewScheduled => 'warning',
            self::Selected => 'success',
            self::Rejected => 'danger',
            self::OnHold => 'gray',
        };
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
