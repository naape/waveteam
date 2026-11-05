<?php

namespace Tests\Feature\Admin;

use App\Filament\EmailQueue\EmailQueueStats;
use App\Filament\EmailQueue\FailedEmails;
use App\Filament\EmailQueue\WaitingEmails;
use App\Filament\Pages\EmailQueue;
use App\Models\AuditLog;
use App\Models\FailedQueueJob;
use App\Models\QueuedJob;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Support\Mail\QueuedEmail;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesApplicants;
use Tests\TestCase;

class EmailQueueTest extends TestCase
{
    use CreatesApplicants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, NotificationTemplateSeeder::class]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['queue.default' => 'database']);
    }

    private function queueEmail(string $email = 'nimal@example.com'): QueuedJob
    {
        $user = User::factory()->applicant()->create(['email' => $email]);
        $user->notify(new TemplatedNotification('service_request.status_changed', ['reference' => 'SR-1', 'title' => 'T', 'status' => 'Approved']));

        return QueuedJob::query()->emails()->latest('id')->firstOrFail();
    }

    private function failEmail(string $error = 'Failed to authenticate on SMTP server'): FailedQueueJob
    {
        $job = $this->queueEmail(Str::random(8).'@example.com');
        $id = DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => $job->payload, 'exception' => $error."\n#0 stack trace", 'failed_at' => now(),
        ]);
        DB::table('jobs')->delete();

        return FailedQueueJob::findOrFail($id);
    }

    public function test_waiting_email_shows_recipient_and_subject(): void
    {
        $job = $this->queueEmail();
        $this->assertSame('nimal@example.com', $job->emailInfo()['to']);
        $this->assertNotSame('', $job->emailInfo()['subject']);
        $this->assertSame(1, QueuedJob::query()->emails()->count()); // not the in-portal copy
        $this->actingAs($this->makeStaff('Super Admin'));

        Livewire::test(WaitingEmails::class)->assertCanSeeTableRecords([$job])->assertSee('nimal@example.com');
    }

    public function test_page_is_for_staff_who_manage_settings(): void
    {
        $this->actingAs($this->makeStaff('Super Admin'));
        $this->get(EmailQueue::getUrl())->assertOk()
            ->assertSeeInOrder(['Email servers (SMTP)', 'Email queue', 'System settings'])
            ->assertSee('Automatic sending');

        $this->actingAs($this->makeStaff('Recruiter'));
        $this->get(EmailQueue::getUrl())->assertForbidden();
    }

    public function test_failed_email_shows_reason_and_can_be_retried(): void
    {
        $failed = $this->failEmail();
        $this->assertSame('Failed to authenticate on SMTP server', $failed->reason());
        $this->actingAs($this->makeStaff('Super Admin'));

        Livewire::test(FailedEmails::class)->assertSee('Failed to authenticate')->callTableAction('retry', $failed);

        $this->assertSame(0, FailedQueueJob::count());
        $this->assertSame(1, QueuedJob::query()->emails()->count());
        $this->assertTrue(AuditLog::where('action', 'email_queue.retried')->exists());
    }

    public function test_failed_email_can_be_deleted_and_all_retried(): void
    {
        $gone = $this->failEmail();
        $this->failEmail();
        $this->failEmail();
        $this->actingAs($this->makeStaff('Super Admin'));

        Livewire::test(FailedEmails::class)->callTableAction('delete', $gone);
        $this->assertSame(2, FailedQueueJob::count());
        $this->assertTrue(AuditLog::where('action', 'email_queue.deleted')->exists());

        Livewire::test(FailedEmails::class)->callAction(\Filament\Actions\Testing\TestAction::make('retryAll')->table());
        $this->assertSame(0, FailedQueueJob::count());
        $this->assertSame(2, QueuedJob::query()->emails()->count());
    }

    public function test_cron_status_follows_the_scheduler_heartbeat(): void
    {
        $this->assertFalse(QueuedEmail::schedulerRunning());
        $this->actingAs($this->makeStaff('Super Admin'));
        Livewire::test(EmailQueueStats::class)->assertSee('Not running');

        QueuedEmail::beat();
        $this->assertTrue(QueuedEmail::schedulerRunning());
        Livewire::test(EmailQueueStats::class)->assertDontSee('Not running')->assertSee('Running');

        $this->travel(10)->minutes();
        $this->assertFalse(QueuedEmail::schedulerRunning());

        Artisan::call('schedule:list');
        $this->assertStringContainsString('portal-heartbeat', Artisan::output());
    }

    public function test_unreadable_job_does_not_break_the_page(): void
    {
        $info = QueuedEmail::describe('{"data":{"command":"not serialized"}}');

        $this->assertSame('—', $info['to']);
    }
}
