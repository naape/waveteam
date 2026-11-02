<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireStaffMfa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesApplicants;
use Tests\TestCase;

class EmailVerificationSwitchTest extends TestCase
{
    use CreatesApplicants, RefreshDatabase;

    public function test_unverified_applicant_is_blocked_by_default(): void
    {
        $user = $this->makeApplicant(user: ['email_verified_at' => null]);

        $this->actingAs($user)->get(route('verification.notice'))->assertOk();
        $this->actingAs($user)->get('/applicants/dashboard')->assertRedirect(route('verification.notice'));
    }

    public function test_unverified_applicant_gets_in_when_switched_off(): void
    {
        config(['portal.email_verification_required' => false]);
        $user = $this->makeApplicant(user: ['email_verified_at' => null]);

        $this->actingAs($user)->get('/applicants/dashboard')->assertOk();
        $this->actingAs($user)->get(route('verification.notice'))->assertRedirect();
    }

    public function test_document_viewer_mfa_check_follows_the_admin_setting(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $staff = $this->makeStaff('HR Manager', mfa: false);
        $request = Request::create('/x');
        $request->setUserResolver(fn () => $staff);
        $next = fn () => response('ok');

        config(['portal.admin_mfa_required' => false]);
        $this->assertSame('ok', (new RequireStaffMfa)->handle($request, $next)->getContent());

        config(['portal.admin_mfa_required' => true]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new RequireStaffMfa)->handle($request, $next);
    }
}
