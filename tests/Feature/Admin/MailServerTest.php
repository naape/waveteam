<?php

namespace Tests\Feature\Admin;

use App\Actions\Admin\MailServerActions;
use App\Filament\Resources\MailServers\Pages\CreateMailServer;
use App\Filament\Resources\MailServers\Pages\EditMailServer;
use App\Filament\Resources\MailServers\Pages\ListMailServers;
use App\Models\AuditLog;
use App\Models\MailServer;
use App\Support\Mail\MailServerConfig;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesApplicants;
use Tests\TestCase;

class MailServerTest extends TestCase
{
    use CreatesApplicants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function formData(array $overrides = []): array
    {
        return array_merge([
            'description' => 'Brevo transactional',
            'host' => 'smtp-relay.brevo.com',
            'port' => 587,
            'encryption' => 'tls',
            'timeout' => 30,
            'username' => 'apikey-user',
            'password' => 'S3cret-smtp-pass',
            'from_address' => 'no-reply@waveteam.lk',
            'from_name' => 'WaveTeam Engineering',
            'reply_to_address' => 'info@waveteam.lk',
            'local_domain' => null,
            'verify_peer' => true,
        ], $overrides);
    }

    private function server(array $overrides = []): MailServer
    {
        return app(MailServerActions::class)->save(new MailServer, $this->formData($overrides), $this->makeStaff('Super Admin'));
    }

    public function test_super_admin_adds_a_server_password_is_encrypted_and_not_audited(): void
    {
        $this->actingAs($this->makeStaff('Super Admin'));

        Livewire::test(CreateMailServer::class)
            ->fillForm($this->formData())
            ->call('create')
            ->assertHasNoFormErrors();

        $server = MailServer::firstOrFail();
        $this->assertSame('S3cret-smtp-pass', $server->password);
        $raw = DB::table('mail_servers')->value('password');
        $this->assertNotSame('S3cret-smtp-pass', $raw);
        $this->assertStringNotContainsString('S3cret', $raw);
        $this->assertFalse($server->is_active); // never active until chosen

        $log = AuditLog::where('action', 'mail_server.created')->firstOrFail();
        $this->assertStringNotContainsString('S3cret', json_encode($log->toArray()));
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->actingAs($this->makeStaff('Super Admin'));

        Livewire::test(CreateMailServer::class)
            ->fillForm($this->formData(['host' => 'smtp server!', 'from_address' => 'not-an-email']))
            ->fillForm(['port' => 70000]) // after encryption, which pre-fills its usual port
            ->call('create')
            ->assertHasFormErrors(['host', 'port', 'from_address']);
    }

    public function test_blank_password_on_edit_keeps_the_saved_one_and_is_never_sent_to_browser(): void
    {
        $server = $this->server();
        $this->actingAs($this->makeStaff('Super Admin'));

        Livewire::test(EditMailServer::class, ['record' => $server->getRouteKey()])
            ->assertFormSet(['password' => null, 'host' => 'smtp-relay.brevo.com'])
            ->assertDontSee('S3cret-smtp-pass')
            ->fillForm(['password' => '', 'port' => 2525])
            ->call('save')
            ->assertHasNoFormErrors();

        $server->refresh();
        $this->assertSame(2525, $server->port);
        $this->assertSame('S3cret-smtp-pass', $server->password);
    }

    public function test_active_server_overrides_env_mail_settings(): void
    {
        $server = $this->server(['encryption' => 'ssl', 'port' => 465, 'verify_peer' => false]);
        $this->assertSame('array', config('mail.default'));

        app(MailServerActions::class)->activate($server, $this->makeStaff('Super Admin'));
        MailServerConfig::apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('smtp-relay.brevo.com', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('apikey-user', config('mail.mailers.smtp.username'));
        $this->assertSame('S3cret-smtp-pass', config('mail.mailers.smtp.password'));
        $this->assertFalse(config('mail.mailers.smtp.verify_peer'));
        $this->assertSame('no-reply@waveteam.lk', config('mail.from.address'));
        $this->assertSame('info@waveteam.lk', config('mail.reply_to.address'));
    }

    public function test_only_one_server_is_active(): void
    {
        $a = $this->server(['description' => 'A']);
        $b = $this->server(['description' => 'B']);
        $staff = $this->makeStaff('Super Admin');

        app(MailServerActions::class)->activate($a, $staff);
        app(MailServerActions::class)->activate($b, $staff);

        $this->assertFalse($a->fresh()->is_active);
        $this->assertTrue($b->fresh()->is_active);
        $this->assertSame('B', MailServer::active()->description);
    }

    public function test_starttls_and_none_map_to_transport_options(): void
    {
        $this->assertTrue($this->server(['encryption' => 'tls'])->mailerConfig()['require_tls']);
        $none = $this->server(['encryption' => 'none'])->mailerConfig();
        $this->assertFalse($none['auto_tls']);
        $this->assertSame('smtp', $none['scheme']);
    }

    public function test_failed_test_email_is_recorded(): void
    {
        // Nothing listens on port 1: the connection is refused immediately.
        $server = $this->server(['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'timeout' => 5]);
        $this->actingAs($this->makeStaff('Super Admin'));

        Livewire::test(ListMailServers::class)
            ->callAction(TestAction::make('test')->table($server), data: ['to' => 'it@waveteam.lk'])
            ->assertNotified();

        $server->refresh();
        $this->assertFalse($server->last_test_ok);
        $this->assertNotNull($server->last_tested_at);
        $this->assertNotEmpty($server->last_test_message);
    }

    public function test_activate_and_delete_from_the_list(): void
    {
        $server = $this->server();
        $this->actingAs($this->makeStaff('Super Admin'));

        Livewire::test(ListMailServers::class)
            ->assertActionHidden(TestAction::make('deactivate')->table($server))
            ->callAction(TestAction::make('activate')->table($server))
            ->assertActionHidden(TestAction::make('delete')->table($server->fresh()));
        $this->assertTrue($server->fresh()->is_active);

        Livewire::test(ListMailServers::class)
            ->callAction(TestAction::make('deactivate')->table($server->fresh()))
            ->callAction(TestAction::make('delete')->table($server->fresh()));
        $this->assertDatabaseCount('mail_servers', 0);
    }

    public function test_only_settings_managers_can_open_it(): void
    {
        $this->actingAs($this->makeStaff('HR Manager'));
        $this->get('/admin/email-servers')->assertForbidden();

        $this->actingAs($this->makeStaff('Super Admin'));
        $this->get('/admin/email-servers')->assertOk();
    }
}
