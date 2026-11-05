<?php

namespace App\Support\Mail;

use App\Notifications\TemplatedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads queued email jobs for the admin "Email queue" page, and tracks whether
 * the cron job that sends them is running.
 */
class QueuedEmail
{
    private const HEARTBEAT_KEY = 'portal.scheduler_heartbeat';

    /** Cron runs every minute, so this allows for a slow run or two. */
    private const HEARTBEAT_MAX_AGE_MINUTES = 3;

    /** Called every minute by the scheduler. */
    public static function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->timestamp);
    }

    public static function lastBeat(): ?Carbon
    {
        $at = Cache::get(self::HEARTBEAT_KEY);

        return $at ? Carbon::createFromTimestamp($at) : null;
    }

    public static function schedulerRunning(): bool
    {
        $last = self::lastBeat();

        return $last !== null && $last->gte(now()->subMinutes(self::HEARTBEAT_MAX_AGE_MINUTES));
    }

    /**
     * @return array{subject: string, to: string}
     */
    public static function describe(string $payload): array
    {
        try {
            $command = unserialize(json_decode($payload, true)['data']['command']);
            $notification = $command->notification;
            $notifiables = collect($command->notifiables);

            return [
                'subject' => self::subject($notification, $notifiables->first()),
                'to' => $notifiables->map(fn ($n) => collect($n->routeNotificationFor('mail', $notification))->flatten()->implode(', '))
                    ->filter()->implode(', ') ?: '—',
            ];
        } catch (Throwable) {
            // e.g. the recipient's account no longer exists.
            return ['subject' => __('Email'), 'to' => '—'];
        }
    }

    private static function subject(object $notification, ?object $notifiable): string
    {
        if ($notification instanceof TemplatedNotification && $notifiable) {
            $template = $notification->template($notifiable);
            if ($template) {
                return $notification->render((string) $template->subject, ['name' => $notifiable->name ?? ''] + $notification->data);
            }
        }

        return Str::headline(class_basename($notification));
    }
}
