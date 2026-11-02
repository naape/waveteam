<?php

namespace Tests\Feature\Auth;

use App\Models\ApplicantProfile;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function applicant(array $attrs = []): User
    {
        $user = User::factory()->applicant()->create(['email' => 'app@example.com'] + $attrs);
        ApplicantProfile::forceCreate(['user_id' => $user->id, 'full_name' => $user->name]);

        return $user;
    }

    private function orgContact(): User
    {
        $user = User::factory()->orgContact()->create(['email' => 'org@example.com']);
        $org = Organization::forceCreate(['name' => 'Acme', 'organization_type' => 'private', 'address' => 'x', 'email' => 'org@example.com', 'phone' => '+94112345678']);
        OrganizationUser::create(['organization_id' => $org->id, 'user_id' => $user->id, 'is_primary' => true]);

        return $user;
    }

    public function test_login_pages_render(): void
    {
        $this->get(route('applicant.login'))->assertOk()->assertSee('Applicant Portal');
        $this->get(route('organization.login'))->assertOk()->assertSee('Service Request Portal');
    }

    public function test_applicant_signs_in_on_applicant_portal(): void
    {
        $user = $this->applicant();

        $this->post(route('applicant.login'), ['email' => 'APP@example.com', 'password' => 'password'])
            ->assertRedirect(route('applicant.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertTrue(AuditLog::where('action', 'auth.login')->where('actor_id', $user->id)->exists());
        $this->get(route('applicant.dashboard'))->assertOk()->assertSee($user->name);
    }

    public function test_organization_signs_in_on_organization_portal(): void
    {
        $this->orgContact();

        $this->post(route('organization.login'), ['email' => 'org@example.com', 'password' => 'password'])
            ->assertRedirect(route('organization.dashboard'));

        $this->get(route('organization.dashboard'))->assertOk()->assertSee('Acme');
    }

    public function test_applicant_cannot_sign_in_on_organization_portal(): void
    {
        $this->applicant();

        $this->post(route('organization.login'), ['email' => 'app@example.com', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'This email is registered on the Applicant Portal. Please sign in there.']);

        $this->assertGuest();
    }

    public function test_wrong_password_gives_generic_error_and_is_audited(): void
    {
        $user = $this->applicant();

        $this->post(route('organization.login'), ['email' => 'app@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

        $this->assertGuest();
        $log = AuditLog::where('action', 'auth.login_failed')->latest('id')->first();
        $this->assertSame('app@example.com', $log->meta['email']);
        $this->assertArrayNotHasKey('password', $log->meta);
    }

    public function test_staff_cannot_sign_in_through_public_portals(): void
    {
        User::factory()->staff()->create(['email' => 'staff@waveteam.lk']);

        foreach (['applicant.login', 'organization.login'] as $route) {
            $this->post(route($route), ['email' => 'staff@waveteam.lk', 'password' => 'password'])
                ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        }

        $this->assertGuest();
    }

    public function test_deactivated_account_cannot_sign_in(): void
    {
        $this->applicant(['status' => 'deactivated']);

        $this->post(route('applicant.login'), ['email' => 'app@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_sign_in_is_rate_limited(): void
    {
        $this->applicant();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('applicant.login'), ['email' => 'app@example.com', 'password' => 'wrong']);
        }

        // Even the correct password is refused while locked out.
        $this->post(route('applicant.login'), ['email' => 'app@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertTrue(AuditLog::where('action', 'auth.lockout')->exists());
    }

    public function test_unverified_user_is_sent_to_verification_notice(): void
    {
        $user = $this->applicant(['email_verified_at' => null]);

        $this->actingAs($user)->get(route('applicant.dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_user_is_kept_inside_their_own_portal(): void
    {
        $this->actingAs($this->applicant())
            ->get(route('organization.dashboard'))
            ->assertRedirect(route('applicant.dashboard'));
    }

    public function test_guest_is_sent_to_the_right_login_page(): void
    {
        $this->get(route('applicant.dashboard'))->assertRedirect(route('applicant.login'));
        $this->get(route('organization.dashboard'))->assertRedirect(route('organization.login'));
    }

    public function test_logout_returns_to_portal_login(): void
    {
        $this->actingAs($this->orgContact())
            ->post(route('logout'))
            ->assertRedirect(route('organization.login'));

        $this->assertGuest();
    }
}
