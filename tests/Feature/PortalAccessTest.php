<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders_both_portals(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Applicant Portal')
            ->assertSee('Service Request Portal');
    }

    public function test_security_headers_are_sent(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_applicant_cannot_access_admin_panel(): void
    {
        $this->actingAs(User::factory()->applicant()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_organization_contact_cannot_access_admin_panel(): void
    {
        $this->actingAs(User::factory()->orgContact()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_staff_without_mfa_is_forced_to_set_it_up(): void
    {
        $staff = User::factory()->staff()->create()->fresh();

        $response = $this->actingAs($staff)->get('/admin');

        $response->assertRedirect();
        $this->assertStringContainsString('multi-factor', $response->headers->get('Location'));
    }

    public function test_deactivated_staff_is_logged_out(): void
    {
        $staff = User::factory()->staff()->deactivated()->create()->fresh();

        $this->actingAs($staff)->get('/admin')->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_super_admin_passes_every_permission_check(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->staff()->create();
        $admin->assignRole(RolesAndPermissionsSeeder::SUPER_ADMIN);

        $this->assertTrue($admin->can('police_clearance.verify'));
        $this->assertTrue($admin->can('any.future.ability'));
    }

    public function test_recruiter_cannot_open_police_clearance_files(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $recruiter = User::factory()->staff()->create();
        $recruiter->assignRole('Recruiter');

        $this->assertTrue($recruiter->can('applicants.view'));
        $this->assertFalse($recruiter->can('police_clearance.view'));
        $this->assertFalse($recruiter->can('police_clearance.verify'));
    }

    public function test_applicant_with_staff_role_still_cannot_bypass_gate(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $applicant = User::factory()->applicant()->create();
        $applicant->assignRole(RolesAndPermissionsSeeder::SUPER_ADMIN); // mis-assignment

        // Gate::before only grants super-admin bypass to staff accounts…
        $this->assertFalse($applicant->can('any.future.ability'));
        // …and the panel itself is staff-only.
        $this->actingAs($applicant)->get('/admin')->assertForbidden();
    }
}
