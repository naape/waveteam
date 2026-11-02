<?php

namespace App\Enums;

/**
 * The two self-service portals. The value is the URL prefix.
 */
enum Portal: string
{
    case Applicant = 'applicants';
    case Organization = 'organizations';

    public function userType(): UserType
    {
        return match ($this) {
            self::Applicant => UserType::Applicant,
            self::Organization => UserType::OrgContact,
        };
    }

    public static function forUser(\App\Models\User $user): ?self
    {
        return match ($user->user_type) {
            UserType::Applicant => self::Applicant,
            UserType::OrgContact => self::Organization,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Applicant => __('Applicant Portal'),
            self::Organization => __('Service Request Portal'),
        };
    }

    /** Route name prefix: "applicant." / "organization." */
    public function routePrefix(): string
    {
        return match ($this) {
            self::Applicant => 'applicant',
            self::Organization => 'organization',
        };
    }

    public function route(string $name, array $params = []): string
    {
        return route($this->routePrefix().'.'.$name, $params);
    }

    public function dashboardUrl(): string
    {
        return $this->route('dashboard');
    }
}
