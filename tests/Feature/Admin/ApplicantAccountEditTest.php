<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Applicants\Pages\ViewApplicant;
use App\Models\ApplicantProfile;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\CreatesApplicants;
use Tests\TestCase;

class ApplicantAccountEditTest extends TestCase
{
    use CreatesApplicants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function applicant(array $profile = [], array $user = []): ApplicantProfile
    {
        return $this->makeApplicant(array_merge([
            'nic_number' => '199012345678',
            'date_of_birth' => '1990-05-01',
            'district_id' => District::first()->id,
            'contact_number' => '+94771234567',
            'permanent_address' => '1 Old Road, Kandy',
            'emergency_contact_name' => 'Kamal',
            'emergency_contact_number' => '+94771112222',
        ], $profile), $user)->applicantProfile;
    }

    private function form(ApplicantProfile $p, array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Nimal Perera',
            'email' => $p->user->email,
            'nic_number' => '199012345678',
            'passport_number' => null,
            'date_of_birth' => '1990-05-01',
            'gender' => 'male',
            'district_id' => District::orderByDesc('id')->first()->id,
            'permanent_address' => '22 New Street, Galle',
            'current_address' => null,
            'contact_number' => '0779876543',
            'whatsapp_number' => null,
            'emergency_contact_name' => 'Sunil',
            'emergency_contact_number' => '0712223333',
            'reason' => 'Applicant called and asked for the update',
        ], $overrides);
    }

    public function test_hr_manager_edits_details_and_change_is_audited(): void
    {
        $p = $this->applicant();
        $this->actingAs($this->makeStaff('HR Manager'));

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('editDetails', data: $this->form($p))
            ->assertHasNoActionErrors();

        $p->refresh();
        $this->assertSame('Nimal Perera', $p->full_name);
        $this->assertSame('Nimal Perera', $p->user->fresh()->name);
        $this->assertSame('22 New Street, Galle', $p->permanent_address);
        $this->assertSame('+94779876543', $p->contact_number);

        $log = AuditLog::where('action', 'applicant.details_updated_by_staff')->firstOrFail();
        $this->assertSame('Applicant called and asked for the update', $log->meta['reason']);
        $this->assertContains('permanent_address', $log->meta['fields']);
        $this->assertNotContains('nic_number', $log->meta['fields']); // unchanged
    }

    public function test_email_change_requires_reverification_and_must_be_unique(): void
    {
        $p = $this->applicant();
        User::factory()->applicant()->create(['email' => 'taken@example.com']);
        $this->actingAs($this->makeStaff('HR Manager'));

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('editDetails', data: $this->form($p, ['email' => 'taken@example.com']))
            ->assertHasActionErrors(['email']);

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('editDetails', data: $this->form($p, ['email' => 'New.Address@Example.com']))
            ->assertHasNoActionErrors();

        $user = $p->user->fresh();
        $this->assertSame('new.address@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_nic_must_be_unique_and_match_date_of_birth(): void
    {
        $p = $this->applicant();
        $this->applicant(['nic_number' => '198512345671', 'date_of_birth' => '1985-01-01']);
        $this->actingAs($this->makeStaff('HR Manager'));

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('editDetails', data: $this->form($p, ['nic_number' => '198512345671', 'date_of_birth' => '1985-01-01']))
            ->assertHasActionErrors(['nic_number']);

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('editDetails', data: $this->form($p, ['date_of_birth' => '1991-05-01']))
            ->assertHasActionErrors(['date_of_birth']);

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('editDetails', data: $this->form($p, ['nic_number' => '199212345678', 'date_of_birth' => '1992-03-03']))
            ->assertHasNoActionErrors();

        $this->assertSame($p->id, ApplicantProfile::findByNic('199212345678')?->id);
    }

    public function test_staff_without_pii_permission_cannot_see_or_change_nic(): void
    {
        $p = $this->applicant(['passport_number' => 'N1234567']);
        $this->actingAs($this->makeStaff('Recruiter'));

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->mountAction('editDetails')
            ->assertActionDataSet(['nic_number' => null])
            ->setActionData($this->form($p, ['nic_number' => '199912345678']))
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $p->refresh();
        $this->assertSame('199012345678', $p->nic_number);
        $this->assertSame('N1234567', $p->passport_number);
        $this->assertSame('Nimal Perera', $p->full_name);
    }

    public function test_set_password_signs_applicant_out_everywhere(): void
    {
        $p = $this->applicant();
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $p->user_id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($this->makeStaff('HR Manager'));

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('setPassword', data: ['password' => 'short', 'password_confirmation' => 'short', 'reason' => 'x'])
            ->assertHasActionErrors(['password']);

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('setPassword', data: ['password' => 'NewSecret2026', 'password_confirmation' => 'NewSecret2026', 'reason' => 'Locked out, verified by phone'])
            ->assertHasNoActionErrors();

        $this->assertTrue(Hash::check('NewSecret2026', $p->user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $p->user_id]);

        $log = AuditLog::where('action', 'account.password_set_by_staff')->firstOrFail();
        $this->assertStringNotContainsString('NewSecret2026', json_encode($log->toArray()));
    }

    public function test_reset_link_can_be_emailed(): void
    {
        $p = $this->applicant();
        $this->actingAs($this->makeStaff('HR Manager'));

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->callAction('resetLink')
            ->assertHasNoActionErrors();

        Notification::assertSentTo($p->user, ResetPassword::class);
        $this->assertTrue(AuditLog::where('action', 'account.password_reset_link_sent')->exists());
    }

    public function test_roles_without_update_permission_do_not_get_the_actions(): void
    {
        $p = $this->applicant();
        $this->actingAs($this->makeStaff('Auditor'));

        Livewire::test(ViewApplicant::class, ['record' => $p->getRouteKey()])
            ->assertActionHidden('editDetails')
            ->assertActionHidden('setPassword')
            ->assertActionHidden('resetLink');
    }
}
