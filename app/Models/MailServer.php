<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * An outgoing (SMTP) email server configured in the admin panel.
 * At most one is active; with none active the .env MAIL_* settings apply.
 */
class MailServer extends Model
{
    public const ENCRYPTIONS = [
        'tls' => 'STARTTLS (usually port 587)',
        'ssl' => 'SSL/TLS (usually port 465)',
        'auto' => 'Automatic (STARTTLS when offered)',
        'none' => 'None (not recommended)',
    ];

    protected $fillable = [
        'description', 'host', 'port', 'encryption', 'username', 'password',
        'from_address', 'from_name', 'reply_to_address', 'timeout', 'local_domain', 'verify_peer',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'port' => 'integer',
            'timeout' => 'integer',
            'verify_peer' => 'boolean',
            'is_active' => 'boolean',
            'last_test_ok' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }

    /** Makes this the only active server. */
    public function activate(): void
    {
        DB::transaction(function () {
            static::where('id', '!=', $this->id)->update(['is_active' => false]);
            $this->forceFill(['is_active' => true])->save();
        });
    }

    /** Laravel "smtp" mailer configuration for this server. */
    public function mailerConfig(): array
    {
        return array_filter([
            'transport' => 'smtp',
            'scheme' => $this->encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username ?: null,
            'password' => $this->password ?: null,
            'timeout' => $this->timeout ?: 30,
            'local_domain' => $this->local_domain ?: null,
            // Symfony Mailer DSN options:
            'require_tls' => $this->encryption === 'tls' ? true : null,
            'auto_tls' => $this->encryption === 'none' ? false : null,
            'verify_peer' => $this->verify_peer ? null : false,
        ], fn ($v) => $v !== null);
    }
}
