<?php

namespace App\Support\Mail;

use App\Models\MailServer;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Applies the admin-configured SMTP server (if one is active) to Laravel's
 * mail configuration. Runs when the mailer is first needed, so pages that
 * send no email do no extra query. Without an active server, .env applies.
 */
class MailServerConfig
{
    public static function apply(): void
    {
        try {
            if (! Schema::hasTable('mail_servers') || ! ($server = MailServer::active())) {
                return;
            }
        } catch (Throwable) {
            return; // database not reachable (e.g. during install) — keep .env settings
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => $server->mailerConfig(),
            'mail.from' => ['address' => $server->from_address, 'name' => $server->from_name],
        ]);

        if ($server->reply_to_address) {
            config(['mail.reply_to' => ['address' => $server->reply_to_address, 'name' => $server->from_name]]);
        }
    }
}
