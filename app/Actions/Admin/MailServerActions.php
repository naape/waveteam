<?php

namespace App\Actions\Admin;

use App\Models\MailServer;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailServerActions
{
    /** Audited fields (the password itself is never logged). */
    private const TRACKED = ['description', 'host', 'port', 'encryption', 'username', 'from_address', 'from_name', 'reply_to_address', 'timeout', 'local_domain', 'verify_peer', 'is_active'];

    public function save(MailServer $server, array $data, User $staff): MailServer
    {
        // A blank password on edit means "keep the saved one".
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $before = $server->exists ? $server->only(self::TRACKED) : [];
        $passwordChanged = array_key_exists('password', $data);
        $server->fill($data);
        $server->updated_by = $staff->id;
        $server->save();

        Audit::log($before ? 'mail_server.updated' : 'mail_server.created', $server, $before, $server->only(self::TRACKED),
            ['password_changed' => $passwordChanged]);

        $this->refreshMailer();

        return $server;
    }

    public function activate(MailServer $server, User $staff): void
    {
        $server->activate();
        Audit::log('mail_server.activated', $server, meta: ['host' => $server->host]);
        $this->refreshMailer();
    }

    public function deactivate(MailServer $server, User $staff): void
    {
        $server->forceFill(['is_active' => false])->save();
        Audit::log('mail_server.deactivated', $server);
        $this->refreshMailer();
    }

    public function delete(MailServer $server, User $staff): void
    {
        Audit::log('mail_server.deleted', $server, $server->only(self::TRACKED));
        $server->delete();
        $this->refreshMailer();
    }

    /**
     * Sends a test email through this server (active or not) and records the result.
     *
     * @return array{0: bool, 1: string}
     */
    public function sendTest(MailServer $server, string $to, User $staff): array
    {
        try {
            Mail::build($server->mailerConfig())->raw(
                __("This is a test email from the WaveTeam Portal.\n\nServer: :desc (:host:port)\nSent by: :name\nTime: :time\n\nIf you received this, the settings work.", [
                    'desc' => $server->description, 'host' => $server->host, 'port' => $server->port,
                    'name' => $staff->name, 'time' => now()->format('j M Y H:i'),
                ]),
                function ($message) use ($server, $to) {
                    $message->to($to)->from($server->from_address, $server->from_name)->subject(__('WaveTeam Portal — SMTP test'));
                    if ($server->reply_to_address) {
                        $message->replyTo($server->reply_to_address);
                    }
                },
            );
            $result = [true, __('Test email sent to :to.', ['to' => $to])];
        } catch (Throwable $e) {
            $result = [false, mb_substr($e->getMessage(), 0, 480)];
        }

        $server->forceFill(['last_tested_at' => now(), 'last_test_ok' => $result[0], 'last_test_message' => $result[1]])->save();
        Audit::log('mail_server.tested', $server, meta: ['to' => $to, 'ok' => $result[0]]);

        return $result;
    }

    /** Make this process pick up the new settings on its next email. */
    private function refreshMailer(): void
    {
        if (app()->resolved('mail.manager')) {
            app('mail.manager')->purge();
            app()->forgetInstance('mailer');
        }
        \App\Support\Mail\MailServerConfig::apply();
    }
}
