<?php

namespace Tests\Feature\Auth;

use App\Enums\OrganizationStatus;
use App\Enums\UserType;
use App\Models\ApplicantProfile;
use App\Models\AuditLog;
use App\Models\ConsentRecord;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\PolicySeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PolicySeeder::class, NotificationTemplateSeeder::class]);
    }

    private function applicantData(array $overrides = []): array
    {
        return array_merge([
            'full_name' => '  Nimal   Perera ',
            'nic_number' => '199012345678',
            'email' => 'Nimal@Example.com',
            'contact_number' => '077 123 4567',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'accept_terms' => '1',
            'accept_privacy' => '1',
            'accept_sensitive' => '1',
        ], $overrides);
    }

    private function organizationData(array $overrides = []): array
    {
        return array_merge([
            'organization_name' => 'Lanka Telecom Contractors (Pvt) Ltd',
            'organization_type' => 'private',
            'registration_number' => 'PV 12345',
            'contact_name' => 'Kumari Silva',
            'designation' => 'Project Manager',
            'email' => 'kumari@ltc.lk',
            'phone' => '0112345678',
            'address' => '12 Galle Road, Colombo 03',
            'billing_same' => '1',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'accept_terms' => '1',
            'accept_privacy' => '1',
        ], $overrides);
    }

    public function test_registration_pages_render(): void
    {
        $this->get(route('applicant.register'))->assertOk()->assertSee('NIC number');
        $this->get(route('organization.register'))->assertOk()->assertSee('Organisation name');
        $this->get(route('applicant.landing'))->assertOk();
        $this->get(route('organization.landing'))->assertOk();
    }

    public function test_applicant_can_register(): void
    {
        Notification::fake();

        $this->post(route('applicant.register'), $this->applicantData())
            ->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'nimal@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(UserType::Applicant, $user->user_type);
        $this->assertNull($user->email_verified_at);

        $profile = $user->applicantProfile;
        $this->assertSame('Nimal Perera', $profile->full_name);
        $this->assertSame('199012345678', $profile->nic_number);
        $this->assertSame('+94771234567', $profile->contact_number);

        // NIC encrypted at rest
        $this->assertStringNotContainsString('199012345678', DB::table('applicant_profiles')->value('nic_number'));

        // Consent recorded for privacy, terms and retention policy versions
        $this->assertSame(3, ConsentRecord::where('user_id', $user->id)->count());

        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertSentTo($user, TemplatedNotification::class, fn ($n) => $n->templateKey === 'applicant.registered');
        $this->assertTrue(AuditLog::where('action', 'auth.registered')->where('auditable_id', $user->id)->exists());
    }

    public function test_applicant_registration_requires_consent(): void
    {
        $this->post(route('applicant.register'), $this->applicantData(['accept_sensitive' => null, 'accept_privacy' => null]))
            ->assertSessionHasErrors(['accept_sensitive', 'accept_privacy']);

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_invalid_and_duplicate_nic_are_rejected(): void
    {
        $this->post(route('applicant.register'), $this->applicantData(['nic_number' => '12345']))
            ->assertSessionHasErrors('nic_number');

        $this->post(route('applicant.register'), $this->applicantData());
        auth()->logout();

        // Same NIC in the other (old) letter case / spacing still counts as duplicate.
        $this->post(route('applicant.register'), $this->applicantData(['email' => 'other@example.com', 'nic_number' => ' 199012345678']))
            ->assertSessionHasErrors('nic_number');

        $this->assertSame(1, ApplicantProfile::count());
    }

    public function test_duplicate_email_is_rejected_case_insensitively(): void
    {
        User::factory()->orgContact()->create(['email' => 'nimal@example.com']);

        $this->post(route('applicant.register'), $this->applicantData(['email' => 'NIMAL@example.com']))
            ->assertSessionHasErrors('email');
    }

    public function test_weak_password_and_bad_phone_are_rejected(): void
    {
        $this->post(route('applicant.register'), $this->applicantData([
            'password' => 'short', 'password_confirmation' => 'short', 'contact_number' => '12',
        ]))->assertSessionHasErrors(['password', 'contact_number']);
    }

    public function test_status_fields_cannot_be_injected_at_registration(): void
    {
        $this->post(route('applicant.register'), $this->applicantData([
            'user_type' => 'staff', 'status' => 'active', 'verification_status' => 'verified',
        ]));

        $user = User::where('email', 'nimal@example.com')->firstOrFail();
        $this->assertSame(UserType::Applicant, $user->user_type);
        $this->assertSame('unverified', $user->applicantProfile->verification_status->value);
    }

    public function test_organization_can_register(): void
    {
        Notification::fake();

        $this->post(route('organization.register'), $this->organizationData())
            ->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'kumari@ltc.lk')->firstOrFail();
        $this->assertSame(UserType::OrgContact, $user->user_type);

        $org = Organization::firstOrFail();
        $this->assertSame(OrganizationStatus::Pending, $org->status);
        $this->assertSame($org->address, $org->billing_address);
        $this->assertSame('+94112345678', $org->phone);
        $this->assertTrue($user->organizationMembership->is_primary);
        $this->assertSame(2, ConsentRecord::where('user_id', $user->id)->count());

        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertSentTo($user, TemplatedNotification::class, fn ($n) => $n->templateKey === 'organization.registered');
    }

    public function test_organization_needs_billing_address_when_different(): void
    {
        $this->post(route('organization.register'), $this->organizationData(['billing_same' => '0', 'billing_address' => '']))
            ->assertSessionHasErrors('billing_address');
    }

    public function test_signed_in_user_cannot_see_registration(): void
    {
        $user = User::factory()->applicant()->create();

        $this->actingAs($user)->get(route('organization.register'))->assertRedirect(route('applicant.dashboard'));
    }
}
