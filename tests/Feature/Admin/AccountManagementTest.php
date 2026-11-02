<?php

namespace Tests\Feature\Admin;

use App\Actions\Accounts\DeactivateUser;
use App\Actions\Accounts\ReactivateUser;
use App\Enums\AccountStatus;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\PortalAccounts\PortalAccountResource;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Http\Middleware\StaffIdleTimeout;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function staffWithRole(string $role): User
    {
        $user = User::factory()->staff()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_deactivation_ends_all_sessions_and_tokens(): void
    {
        $admin = $this->staffWithRole('Super Admin');
        $target = User::factory()->applicant()->create();
        $target->createToken('mobile');
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin);
        app(DeactivateUser::class)($target, $admin, 'Submitted forged certificate');

        $target->refresh();
        $this->assertSame(AccountStatus::Deactivated, $target->status);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertSame(0, $target->tokens()->count());

        $log = AuditLog::where('action', 'user.deactivated')->firstOrFail();
        $this->assertSame('Submitted forged certificate', $log->meta['reason']);
        $this->assertSame($admin->id, $log->actor_id);

        app(ReactivateUser::class)($target, $admin);
        $this->assertSame(AccountStatus::Active, $target->fresh()->status);
    }

    public function test_cannot_deactivate_self_or_without_reason(): void
    {
        $admin = $this->staffWithRole('Super Admin');

        $this->expectException(InvalidArgumentException::class);
        app(DeactivateUser::class)($admin, $admin, 'x');
    }

    public function test_deactivation_requires_reason(): void
    {
        $admin = $this->staffWithRole('Super Admin');

        $this->expectException(InvalidArgumentException::class);
        app(DeactivateUser::class)(User::factory()->applicant()->create(), $admin, '  ');
    }

    public function test_admin_resource_access_follows_permissions(): void
    {
        $this->actingAs($this->staffWithRole('Recruiter'));
        $this->assertFalse(StaffResource::canViewAny());
        $this->assertFalse(AuditLogResource::canViewAny());
        $this->assertFalse(PortalAccountResource::canViewAny());

        $this->actingAs($this->staffWithRole('Auditor'));
        $this->assertTrue(AuditLogResource::canViewAny());
        $this->assertFalse(AuditLogResource::canEdit(new AuditLog));
        $this->assertFalse(StaffResource::canViewAny());

        $this->actingAs($this->staffWithRole('HR Manager'));
        $this->assertTrue(PortalAccountResource::canViewAny());
        $this->assertTrue(PortalAccountResource::canManage(User::factory()->applicant()->create()));
        $this->assertFalse(PortalAccountResource::canManage(User::factory()->orgContact()->create()));

        $this->actingAs($this->staffWithRole('Super Admin'));
        $this->assertTrue(StaffResource::canViewAny());
        $this->assertFalse(StaffResource::canDelete(new User));
    }

    public function test_staff_manager_cannot_edit_a_super_admin_unless_super_admin(): void
    {
        $superAdmin = $this->staffWithRole('Super Admin');
        $manager = User::factory()->staff()->create();
        $manager->givePermissionTo('staff.manage');

        $this->actingAs($manager->fresh());
        $this->assertFalse(StaffResource::canEdit($superAdmin));

        $this->actingAs($this->staffWithRole('Super Admin'));
        $this->assertTrue(StaffResource::canEdit($superAdmin));
    }

    public function test_admin_pages_load_for_super_admin_with_mfa(): void
    {
        $admin = $this->staffWithRole('Super Admin');
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        // Simulate a completed MFA challenge by acting as the user directly.
        foreach (['/admin/staff', '/admin/portal-accounts', '/admin/audit-logs', '/admin/staff/create'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_recruiter_is_forbidden_from_staff_admin(): void
    {
        $recruiter = $this->staffWithRole('Recruiter');
        $recruiter->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->actingAs($recruiter)->get('/admin/staff')->assertForbidden();
        $this->actingAs($recruiter)->get('/admin/audit-logs')->assertForbidden();
    }

    public function test_idle_staff_session_times_out(): void
    {
        $admin = $this->staffWithRole('Super Admin');
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->actingAs($admin)
            ->withSession(['staff_last_activity' => time() - 31 * 60])
            ->get('/admin')
            ->assertRedirect(url('admin/login'));

        $this->assertGuest();
        $this->assertTrue(AuditLog::where('action', 'auth.idle_timeout')->exists());
    }
}
