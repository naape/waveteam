<?php

namespace Tests\Feature\Auth;

use App\Models\ApplicantProfile;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PasswordAndVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function applicant(array $attrs = []): User
    {
        $user = User::factory()->applicant()->create(['email' => 'app@example.com'] + $attrs);
        ApplicantProfile::forceCreate(['user_id' => $user->id, 'full_name' => $user->name]);

        return $user;
    }

    public function test_reset_link_is_sent_only_for_matching_portal_type(): void
    {
        Notification::fake();
        $user = $this->applicant();

        // Wrong portal: same friendly message, but no email.
        $this->post(route('organization.password.email'), ['email' => 'app@example.com'])->assertSessionHas('status');
        Notification::assertNothingSent();

        $this->post(route('applicant.password.email'), ['email' => 'app@example.com'])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unknown_email_gets_same_response(): void
    {
        Notification::fake();

        $this->post(route('applicant.password.email'), ['email' => 'nobody@example.com'])
            ->assertSessionHas('status', 'If an account exists for that email, we have sent a password reset link. Please check your inbox.');

        Notification::assertNothingSent();
    }

    public function test_password_can_be_reset_and_other_sessions_are_ended(): void
    {
        Notification::fake();
        $user = $this->applicant();
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->post(route('applicant.password.email'), ['email' => 'app@example.com']);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $this->post(route('password.update'), [
                'token' => $n->token,
                'email' => $user->email,
                'password' => 'BrandNewPass99',
                'password_confirmation' => 'BrandNewPass99',
            ])->assertRedirect(route('applicant.login'));

            return true;
        });

        $this->assertTrue(Hash::check('BrandNewPass99', $user->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertTrue(AuditLog::where('action', 'auth.password_reset')->exists());
    }

    public function test_email_can_be_verified_with_signed_link(): void
    {
        $user = $this->applicant(['email_verified_at' => null]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($url)->assertRedirect(route('applicant.dashboard'));

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertTrue(AuditLog::where('action', 'auth.email_verified')->exists());
    }

    public function test_tampered_verification_link_is_rejected(): void
    {
        $user = $this->applicant(['email_verified_at' => null]);
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('other@example.com')]);

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verification_email_uses_template_and_can_be_resent(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        Notification::fake();
        $user = $this->applicant(['email_verified_at' => null]);

        $this->actingAs($user)->post(route('verification.send'))->assertSessionHas('status');

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $n) use ($user) {
            $mail = $n->toMail($user);
            $this->assertSame('Verify your WaveTeam account', $mail->subject);

            return true;
        });
    }

    public function test_user_can_change_password_and_sign_out_other_devices(): void
    {
        $user = $this->applicant();
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->get(route('account.security'))->assertOk()->assertSee('Account security');

        $this->actingAs($user)->put(route('account.password'), [
            'current_password' => 'wrong',
            'password' => 'AnotherPass11',
            'password_confirmation' => 'AnotherPass11',
        ])->assertSessionHasErrorsIn('password', 'current_password');

        $this->actingAs($user)->put(route('account.password'), [
            'current_password' => 'password',
            'password' => 'AnotherPass11',
            'password_confirmation' => 'AnotherPass11',
        ])->assertSessionHas('status');

        $this->assertTrue(Hash::check('AnotherPass11', $user->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('id', 'other-device')->count());
        $this->assertTrue(AuditLog::where('action', 'account.password_changed')->exists());
    }

    public function test_staff_cannot_use_portal_account_pages(): void
    {
        $this->actingAs(User::factory()->staff()->create())->get(route('account.security'))->assertForbidden();
    }

    public function test_policy_pages_render(): void
    {
        $this->seed(\Database\Seeders\PolicySeeder::class);

        $this->get(route('policy', 'privacy-policy'))->assertOk()->assertSee('Personal Data Protection Act');
        $this->get(route('policy', 'terms'))->assertOk();
        $this->get(route('policy', 'data-retention'))->assertOk();
    }
}
