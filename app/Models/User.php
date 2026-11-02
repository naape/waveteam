<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\UserType;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasName, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * user_type, status and verification fields are deliberately NOT fillable:
     * they are only ever set explicitly by trusted code paths.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'user_type' => UserType::class,
            'status' => AccountStatus::class,
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    // ---- Portal / type helpers -------------------------------------------------

    public function isApplicant(): bool
    {
        return $this->user_type === UserType::Applicant;
    }

    public function isOrgContact(): bool
    {
        return $this->user_type === UserType::OrgContact;
    }

    public function isStaff(): bool
    {
        return $this->user_type === UserType::Staff;
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    public function scopeStaff(Builder $query): Builder
    {
        return $query->where('user_type', UserType::Staff);
    }

    // ---- Filament ---------------------------------------------------------------

    /** Only active staff may ever reach the admin panel. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->isStaff() && $this->isActive();
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    // ---- Notification routing (SMS / WhatsApp) -------------------------------

    public function routeNotificationForSms(): ?string
    {
        return match (true) {
            $this->isApplicant() => $this->applicantProfile?->contact_number,
            $this->isOrgContact() => $this->organizationMembership?->phone,
            default => null,
        };
    }

    public function routeNotificationForWhatsapp(): ?string
    {
        return $this->isApplicant()
            ? ($this->applicantProfile?->whatsapp_number ?: $this->applicantProfile?->contact_number)
            : $this->routeNotificationForSms();
    }

    // ---- Relationships ----------------------------------------------------------

    public function applicantProfile(): HasOne
    {
        return $this->hasOne(ApplicantProfile::class);
    }

    public function organizationMembership(): HasOne
    {
        return $this->hasOne(OrganizationUser::class);
    }

    public function consentRecords(): HasMany
    {
        return $this->hasMany(ConsentRecord::class);
    }
}
