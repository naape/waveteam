<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountStatus;
use App\Enums\ServiceRequestStatus;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Filament\Resources\Staff\Pages\ListStaff;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\AuditLog;
use App\Models\ServiceRequestMessage;
use App\Models\User;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesApplicants;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class StaffRemovalTest extends TestCase
{
    use CreatesApplicants, CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_removed_staff_are_signed_out_hidden_and_kept_on_past_work(): void
    {
        $target = $this->makeStaff('Recruiter');
        $target->createToken('mobile');
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        $request = $this->makeServiceRequest($this->makeOrgContact());
        $message = ServiceRequestMessage::create(['service_request_id' => $request->id, 'sender_id' => $target->id, 'body' => 'Hello', 'is_internal' => false]);
        $this->actingAs($this->makeStaff('Super Admin'));

        Livewire::test(ListStaff::class)
            ->callTableAction('remove', $target, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);
        $this->assertNotSoftDeleted($target);

        Livewire::test(ListStaff::class)
            ->callTableAction('remove', $target, data: ['reason' => 'Left the company'])
            ->assertHasNoTableActionErrors();

        $this->assertSoftDeleted($target);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $target->id)->count());
        $this->assertFalse(Auth::validate(['email' => $target->email, 'password' => 'password']));
        $this->assertSame('Left the company', AuditLog::where('action', 'staff.removed')->firstOrFail()->meta['reason']);
        $this->assertSame($target->name, $message->fresh()->sender->name);

        Livewire::test(ListStaff::class)->assertCanNotSeeTableRecords([$target]);
        Livewire::test(ListStaff::class)->filterTable('trashed', false)->assertCanSeeTableRecords([$target]);
    }

    public function test_restored_staff_stay_deactivated_until_reactivated(): void
    {
        $target = $this->makeStaff('Recruiter');
        $this->actingAs($this->makeStaff('Super Admin'));
        Livewire::test(ListStaff::class)->callTableAction('remove', $target, data: ['reason' => 'Left']);
        $removed = User::withTrashed()->findOrFail($target->id);

        $this->get(StaffResource::getUrl('edit', ['record' => $removed]))->assertForbidden();

        Livewire::test(ListStaff::class)->filterTable('trashed', false)
            ->assertTableActionHidden('edit', $removed)
            ->assertTableActionHidden('reactivate', $removed)
            ->assertTableActionHidden('remove', $removed)
            ->callTableAction('restore', $removed);

        $this->assertNotSoftDeleted($target);
        $this->assertSame(AccountStatus::Deactivated, $target->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'staff.restored')->exists());
        Livewire::test(ListStaff::class)->assertTableActionVisible('reactivate', $target->fresh());
    }

    public function test_nobody_removes_themselves(): void
    {
        $admin = $this->makeStaff('Super Admin');
        $this->actingAs($admin);

        Livewire::test(ListStaff::class)->assertTableActionHidden('remove', $admin);
        $this->assertThrows(fn () => app(\App\Actions\Admin\StaffAccountActions::class)->remove($admin, $admin, 'x'),
            \Illuminate\Validation\ValidationException::class);
    }

    public function test_removal_needs_permission_and_super_admins_are_protected(): void
    {
        $target = $this->makeStaff('Recruiter');
        $superAdmin = $this->makeStaff('Super Admin');

        // staff.manage alone is not enough.
        $manager = $this->makeStaff('Recruiter');
        $manager->givePermissionTo('staff.manage');
        $this->actingAs($manager);
        Livewire::test(ListStaff::class)->assertTableActionHidden('remove', $target);

        // staff.delete still cannot touch a Super Admin.
        Role::findByName('Recruiter')->givePermissionTo('staff.manage', 'staff.delete');
        $this->actingAs($manager->fresh());
        Livewire::test(ListStaff::class)
            ->assertTableActionVisible('remove', $target)
            ->assertTableActionHidden('remove', $superAdmin);
    }

    public function test_manager_of_an_open_request_must_be_replaced_first(): void
    {
        $target = $this->makeStaff('Operations Manager');
        $request = $this->makeServiceRequest($this->makeOrgContact(), ServiceRequestStatus::UnderReview);
        $request->forceFill(['assigned_manager_id' => $target->id])->save();
        $this->actingAs($this->makeStaff('Super Admin'));

        Livewire::test(ListStaff::class)
            ->callTableAction('remove', $target, data: ['reason' => 'Left'])
            ->assertNotified('This staff member cannot be removed yet');
        $this->assertNotSoftDeleted($target);
        $this->assertSame(AccountStatus::Active, $target->fresh()->status);

        $request->forceFill(['status' => ServiceRequestStatus::Cancelled])->save();
        Livewire::test(ListStaff::class)->callTableAction('remove', $target, data: ['reason' => 'Left']);
        $this->assertSoftDeleted($target);
        $this->assertSame($target->name, $request->fresh()->manager->name);
    }
}
