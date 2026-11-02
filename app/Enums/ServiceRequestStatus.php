<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ServiceRequestStatus: string implements HasLabel, HasColor
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case QuotationPreparation = 'quotation_preparation';
    case QuotationSent = 'quotation_sent';
    case Approved = 'approved';
    case TeamAssigned = 'team_assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under Review',
            self::QuotationPreparation => 'Quotation Preparation',
            self::QuotationSent => 'Quotation Sent',
            self::Approved => 'Approved',
            self::TeamAssigned => 'Team Assigned',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Submitted => 'gray',
            self::UnderReview => 'info',
            self::QuotationPreparation => 'info',
            self::QuotationSent => 'warning',
            self::Approved => 'primary',
            self::TeamAssigned => 'primary',
            self::InProgress => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Statuses staff may move a request to from this one. "Quotation Sent" is
     * reached only by sending a quotation; "Approved" normally by the client
     * accepting it (staff may record an offline acceptance).
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Submitted => [self::UnderReview, self::Cancelled],
            self::UnderReview => [self::QuotationPreparation, self::Cancelled],
            self::QuotationPreparation => [self::UnderReview, self::Cancelled],
            self::QuotationSent => [self::UnderReview, self::Approved, self::Cancelled],
            self::Approved => [self::TeamAssigned, self::Cancelled],
            self::TeamAssigned => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed],
            self::Completed, self::Cancelled => [],
        };
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** @return array<string,string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
