<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Records every outbound email (sent or failed) in notification_logs.
 *
 * Registered automatically by Laravel's listener discovery (handle* methods).
 */
class NotificationLogSubscriber
{
    public function handleSent(NotificationSent $event): void
    {
        $this->log($event->notifiable, $event->notification, $event->channel, 'sent');
    }

    public function handleFailed(NotificationFailed $event): void
    {
        $error = $event->data['exception'] ?? null;
        $this->log($event->notifiable, $event->notification, $event->channel, 'failed', $error instanceof \Throwable ? $error->getMessage() : null);
    }

    private function log(object $notifiable, object $notification, string $channel, string $status, ?string $error = null): void
    {
        $channel = match ($channel) {
            \App\Notifications\Channels\SmsChannel::class => 'sms',
            \App\Notifications\Channels\WhatsAppChannel::class => 'whatsapp',
            default => $channel,
        };

        if (! in_array($channel, ['mail', 'sms', 'whatsapp'], true)) {
            return;
        }

        NotificationLog::create([
            'user_id' => $notifiable instanceof User ? $notifiable->id : null,
            'template_key' => $notification instanceof TemplatedNotification ? $notification->templateKey : class_basename($notification),
            'channel' => $channel,
            'recipient' => (string) ($notifiable->email ?? $notifiable->routeNotificationFor($channel) ?? ''),
            'status' => $status,
            'error' => $error ? mb_substr($error, 0, 2000) : null,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}
