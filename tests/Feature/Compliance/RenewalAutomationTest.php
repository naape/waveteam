<?php

namespace Tests\Feature\Compliance;

use App\Actions\Compliance\OpsExpiryDigest;
use App\Actions\Compliance\SendPoliceClearanceReminders;
use App\Enums\QuotationStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\VacancyStatus;
use App\Models\ApplicantDocument;
use App\Models\JobVacancy;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\PoliceClearanceRecord;
use App\Models\StoredFile;
use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\TemplatedNotification;
use App\Support\Settings;
use Database\Seeders\DemoVacancySeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesApplicants;
use Tests\Concerns\CreatesOrganizations;
use Tests\TestCase;

class RenewalAutomationTest extends TestCase
{
    use CreatesApplicants, CreatesOrganizations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 06:00');
        $this->seed([ReferenceDataSeeder::class, RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class]);
    }

    /** An applicant whose approved certificate in force is due on $due. */
    private function certifiedApplicant(string $due, array $profile = []): array
    {
        $user = $this->makeApplicant(array_merge(['contact_number' => '+94771234567'], $profile));
        $file = StoredFile::create(['uuid' => (string) Str::uuid(), 'disk' => 'documents', 'path' => 'x', 'original_name' => 'pcc.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64)]);
        $doc = ApplicantDocument::create(['applicant_profile_id' => $user->applicantProfile->id, 'document_type' => 'police_clearance', 'stored_file_id' => $file->id]);
        $record = PoliceClearanceRecord::forceCreate([
            'applicant_profile_id' => $user->applicantProfile->id, 'applicant_document_id' => $doc->id,
            'issue_date' => Carbon::parse($due)->subYear(), 'uploaded_at' => Carbon::parse($due)->subYear(),
            'verification_status' => 'approved', 'valid_until' => $due, 'verified_at' => Carbon::parse($due)->subYear(),
        ]);
        $user->applicantProfile->forceFill(['current_police_clearance_id' => $record->id])->save();

        return [$user, $record];
    }

    private function run_(): array
    {
        return app(SendPoliceClearanceReminders::class)();
    }

    private function remindersFor(User $user): array
    {
        return Notification::sent($user, TemplatedNotification::class)->map(fn ($n) => $n->templateKey.($n->data['days'] ?? ''))->all();
    }

    public function test_reminders_at_30_14_7_days_each_once_then_expiry_notice(): void
    {
        Notification::fake();
        [$user, $record] = $this->certifiedApplicant('2026-12-02'); // 30 days away

        $this->assertSame(1, $this->run_()['reminders']);
        $this->run_(); // same day again: nothing new
        $this->assertSame(['pcc.reminder30'], $this->remindersFor($user));

        Carbon::setTestNow('2026-11-10'); // 22 days: between thresholds
        $this->assertSame(0, $this->run_()['reminders']);

        Carbon::setTestNow('2026-11-18'); // 14 days
        $this->run_();
        Carbon::setTestNow('2026-11-25'); // 7 days
        $this->run_();
        $this->run_();
        Carbon::setTestNow('2026-12-02'); // due date itself: still in force, no notice
        $this->run_();
        Carbon::setTestNow('2026-12-03'); // expired
        $this->assertSame(1, $this->run_()['expired']);
        Carbon::setTestNow('2026-12-10');
        $this->run_();

        $this->assertSame(['pcc.reminder30', 'pcc.reminder14', 'pcc.reminder7', 'pcc.expired'], $this->remindersFor($user));
        $this->assertSame(['d30', 'd14', 'd7', 'expired'], $record->reminders()->orderBy('id')->pluck('reminder_type')->all());
    }

    public function test_late_approval_sends_only_the_most_urgent_reminder(): void
    {
        Notification::fake();
        [$user, $record] = $this->certifiedApplicant('2026-11-12'); // 10 days away: 30 and 14 both passed

        $stats = $this->run_();

        $this->assertSame(['reminders' => 1, 'expired' => 0, 'skipped' => 1], $stats);
        $this->assertSame(['pcc.reminder10'], $this->remindersFor($user));
        $this->assertEqualsCanonicalizing(['d30', 'd14'], $record->reminders()->pluck('reminder_type')->all());

        Carbon::setTestNow('2026-11-05'); // 7 days: the 7-day reminder still comes
        $this->run_();
        $this->assertCount(2, $this->remindersFor($user));
    }

    public function test_no_reminders_while_renewal_awaits_verification(): void
    {
        Notification::fake();
        [$user, $record] = $this->certifiedApplicant('2026-11-20');
        PoliceClearanceRecord::forceCreate([
            'applicant_profile_id' => $record->applicant_profile_id, 'applicant_document_id' => $record->applicant_document_id,
            'issue_date' => '2026-10-30', 'uploaded_at' => now(), 'verification_status' => 'pending_verification',
        ]);

        $this->run_();
        Notification::assertNothingSentTo($user);

        // ...but if it expires before review, the expiry notice still goes out.
        Carbon::setTestNow('2026-11-21');
        $this->run_();
        $this->assertSame(['pcc.expired'], $this->remindersFor($user));
    }

    public function test_configurable_days_superseded_and_inactive_are_respected(): void
    {
        Notification::fake();
        Settings::set('pcc.reminder_days', [60, 3]);
        [$user] = $this->certifiedApplicant('2026-12-22'); // 50 days
        [$superseded, $old] = $this->certifiedApplicant('2026-11-20');
        $old->forceFill(['superseded_at' => now()])->save();
        [$deactivated] = $this->certifiedApplicant('2026-11-20');
        $deactivated->forceFill(['status' => 'deactivated'])->save();

        $this->run_();

        $this->assertSame(['pcc.reminder50'], $this->remindersFor($user));
        Notification::assertNothingSentTo($superseded);
        Notification::assertNothingSentTo($deactivated);
    }

    public function test_reminder_command_and_audit_of_expiry(): void
    {
        $this->certifiedApplicant('2026-11-01'); // expired yesterday

        $this->artisan('pcc:send-reminders')->expectsOutputToContain('expiry notices: 1')->assertSuccessful();
        $this->artisan('pcc:send-reminders')->expectsOutputToContain('expiry notices: 0')->assertSuccessful();
        $this->assertSame(1, NotificationLog::where('template_key', 'pcc.expired')->where('status', 'sent')->count());
    }

    public function test_ops_digest_lists_assignments_at_risk(): void
    {
        Notification::fake();
        $ops = $this->makeStaff('Operations Manager');
        $contact = $this->makeOrgContact();
        $request = $this->makeServiceRequest($contact, ServiceRequestStatus::InProgress, ['requires_police_clearance' => true]);
        $noPcc = $this->makeServiceRequest($contact, ServiceRequestStatus::InProgress, ['requires_police_clearance' => false]);

        [$atRisk] = $this->certifiedApplicant('2026-11-20');
        [$fine] = $this->certifiedApplicant('2027-06-01');
        [$exempt] = $this->certifiedApplicant('2026-11-10');

        $request->assignments()->create(['applicant_profile_id' => $atRisk->applicantProfile->id, 'role_title' => 'Rigger', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'status' => 'active']);
        $request->assignments()->create(['applicant_profile_id' => $fine->applicantProfile->id, 'role_title' => 'Supervisor', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'status' => 'active']);
        $noPcc->assignments()->create(['applicant_profile_id' => $exempt->applicantProfile->id, 'role_title' => 'Driver', 'start_date' => '2026-10-01', 'end_date' => '2026-12-31', 'status' => 'active']);

        $this->assertSame(1, app(OpsExpiryDigest::class)());
        Notification::assertSentTo($ops, TemplatedNotification::class, fn ($n) => $n->templateKey === 'pcc.ops_digest'
            && str_contains($n->data['list'], $atRisk->applicantProfile->full_name)
            && ! str_contains($n->data['list'], $fine->applicantProfile->full_name));
    }

    public function test_housekeeping_jobs(): void
    {
        $this->seed(DemoVacancySeeder::class);
        $past = JobVacancy::first();
        $past->update(['closing_date' => '2026-11-01']);
        $request = $this->makeServiceRequest($this->makeOrgContact(), ServiceRequestStatus::QuotationSent);
        $expired = $this->makeQuotation($request, attrs: ['valid_until' => '2026-11-01']);
        $valid = $this->makeQuotation($request, attrs: ['valid_until' => '2026-11-02']);

        $this->artisan('vacancies:close-expired')->assertSuccessful();
        $this->artisan('quotations:expire')->assertSuccessful();

        $this->assertSame(VacancyStatus::Closed, $past->fresh()->status);
        $this->assertSame(VacancyStatus::Published, JobVacancy::whereKeyNot($past->id)->first()->status);
        $this->assertSame(QuotationStatus::Expired, $expired->fresh()->status);
        $this->assertSame(QuotationStatus::Sent, $valid->fresh()->status);
    }

    public function test_scheduler_registers_all_jobs_in_colombo_time(): void
    {
        $events = collect(app(Schedule::class)->events());
        $find = fn (string $cmd) => $events->first(fn ($e) => str_contains($e->command ?? '', $cmd));

        $this->assertSame('0 6 * * *', $find('pcc:send-reminders')->expression);
        $this->assertSame('Asia/Colombo', $find('pcc:send-reminders')->timezone);
        $this->assertSame('0 7 * * 1', $find('pcc:ops-expiry-digest')->expression);
        $this->assertNotNull($find('vacancies:close-expired'));
        $this->assertNotNull($find('quotations:expire'));
        $this->assertNotNull($find('queue:work'));
    }

    public function test_sms_channel_is_off_by_default_and_uses_gateway_when_enabled(): void
    {
        [$user] = $this->certifiedApplicant('2026-12-02');
        $notification = new TemplatedNotification('pcc.reminder', ['days' => 30, 'renewal_date' => '2 December 2026']);

        $this->assertNotContains(SmsChannel::class, $notification->via($user));

        config(['portal.channels.sms.enabled' => true]);
        $this->assertNotContains(SmsChannel::class, $notification->via($user), 'template still disabled');

        NotificationTemplate::where(['key' => 'pcc.reminder', 'channel' => 'sms'])->update(['is_enabled' => true]);
        $this->assertContains(SmsChannel::class, $notification->via($user));

        Log::spy();
        $user->notify($notification);
        Log::shouldHaveReceived('info')->withArgs(fn ($msg) => str_starts_with($msg, '[sms] to +947')
            && str_contains($msg, 'renewed by 2 December 2026')
            && ! str_contains($msg, '+94771234567'));
        $this->assertSame(1, NotificationLog::where('channel', 'sms')->where('status', 'sent')->count());
    }
}
