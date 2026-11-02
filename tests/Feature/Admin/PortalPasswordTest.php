<?php

namespace Tests\Feature\Admin;

use App\Actions\Accounts\PortalPasswordActions;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Resources\PortalAccounts\Pages\ListPortalAccounts;
use App\Models\AuditLog;
use App\Models\OrganizationUser;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Concerns\CreatesApplicants;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class PortalPasswordTest extends TestCase
{
    use CreatesApplicants, CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function setPasswordData(string $password = 'NewSecret2026', ?int $userId = null): array
    {
        return array_filter(['user_id' => $userId, 'password' => $password, 'password_confirmation' => $password, 'reason' => 'Requested by phone'], fn ($v) => $v !== null);
    }

    public function test_set_organisation_contact_password_from_organisation_page(): void
    {
        $contact = $this->makeOrgContact();
        $org = $contact->organizationMembership->organization;
        $second = User::factory()->orgContact()->create();
        OrganizationUser::create(['organization_id' => $org->id, 'user_id' => $second->id, 'designation' => 'Engineer', 'is_primary' => false]);
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $second->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->makeStaff('Operations Manager'));

        Livewire::test(ViewOrganization::class, ['record' => $org->getRouteKey()])
            ->callAction('setPassword', data: $this->setPasswordData(userId: $second->id))
            ->assertHasNoActionErrors();

        $this->assertTrue(Hash::check('NewSecret2026', $second->fresh()->password));
        $this->assertFalse(Hash::check('NewSecret2026', $contact->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $second->id]);
        $log = AuditLog::where('action', 'account.password_set_by_staff')->firstOrFail();
        $this->assertSame($second->id, $log->auditable_id);
        $this->assertStringNotContainsString('NewSecret2026', json_encode($log->toArray()));
    }

    public function test_cannot_target_an_account_outside_the_organisation(): void
    {
        $contact = $this->makeOrgContact();
        $outsider = $this->makeOrgContact();
        $this->actingAs($this->makeStaff('Operations Manager'));

        Livewire::test(ViewOrganization::class, ['record' => $contact->organizationMembership->organization->getRouteKey()])
            ->callAction('setPassword', data: $this->setPasswordData(userId: $outsider->id));

        $this->assertFalse(Hash::check('NewSecret2026', $outsider->fresh()->password));
    }

    public function test_reset_link_for_organisation_contact(): void
    {
        $contact = $this->makeOrgContact();
        $this->actingAs($this->makeStaff('Operations Manager'));

        Livewire::test(ViewOrganization::class, ['record' => $contact->organizationMembership->organization->getRouteKey()])
            ->callAction('resetLink')
            ->assertHasNoActionErrors();

        Notification::assertSentTo($contact, ResetPassword::class);
    }

    public function test_portal_accounts_list_changes_applicant_and_organisation_passwords(): void
    {
        $applicant = $this->makeApplicant();
        $contact = $this->makeOrgContact();
        $this->actingAs($this->makeStaff('Super Admin'));

        foreach ([$applicant, $contact] as $user) {
            Livewire::test(ListPortalAccounts::class)
                ->callAction(TestAction::make('setPassword')->table($user), data: $this->setPasswordData())
                ->assertHasNoActionErrors();
            $this->assertTrue(Hash::check('NewSecret2026', $user->fresh()->password));
        }
    }

    public function test_permissions_follow_account_type(): void
    {
        $applicant = $this->makeApplicant();
        $contact = $this->makeOrgContact();

        // HR Manager: applicants yes, organisations no.
        $this->actingAs($this->makeStaff('HR Manager'));
        Livewire::test(ListPortalAccounts::class)
            ->assertActionVisible(TestAction::make('setPassword')->table($applicant))
            ->assertActionHidden(TestAction::make('setPassword')->table($contact));

        // Finance: neither (no access to the list or the organisation action).
        $this->actingAs($this->makeStaff('Finance'));
        $this->get('/admin/portal-accounts')->assertForbidden();
        Livewire::test(ViewOrganization::class, ['record' => $contact->organizationMembership->organization->getRouteKey()])
            ->assertActionHidden('setPassword');
    }

    public function test_staff_passwords_cannot_be_changed_this_way(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(PortalPasswordActions::class)->setPassword($this->makeStaff('HR Manager'), 'NewSecret2026', $this->makeStaff('Super Admin'), 'x');
    }
}
