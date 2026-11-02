<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sends an admin-editable template (notification_templates) by key.
 *
 * Body is Markdown; {{placeholders}} are replaced with $data values. Values are
 * HTML-escaped by the mail layout, so user input cannot inject markup.
 * Additional channels (sms, whatsapp) plug in later via via().
 */
class TemplatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, scalar|null>  $data
     */
    public function __construct(
        public readonly string $templateKey,
        public readonly array $data = [],
        public readonly ?string $actionUrl = null,
        public readonly ?string $actionText = null,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        $template = $this->template($notifiable);

        if ($template === null || (! $template->is_enabled && ! $template->is_mandatory)) {
            return [];
        }

        $channels = ($template->is_mandatory || ! $this->optedOut($notifiable, 'mail')) ? ['mail'] : [];

        // Optional SMS / WhatsApp, when the channel is switched on and an
        // enabled template exists for this key.
        foreach (['sms' => Channels\SmsChannel::class, 'whatsapp' => Channels\WhatsAppChannel::class] as $name => $class) {
            if (config("portal.channels.{$name}.enabled")
                && $this->template($notifiable, $name)?->is_enabled
                && ! $this->optedOut($notifiable, $name)
                && filled($notifiable->routeNotificationFor($name, $this))) {
                $channels[] = $class;
            }
        }

        // Portal users also get an in-app copy on their dashboard.
        if ($notifiable instanceof \App\Models\User && ! $notifiable->isStaff()) {
            $channels[] = 'database';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = $this->template($notifiable);
        $data = ['name' => $notifiable->name ?? ''] + $this->data;

        $mail = (new MailMessage)
            ->subject($this->render((string) $template->subject, $data))
            ->greeting(__('Hello :name,', ['name' => $data['name']]));

        foreach (preg_split('/\n\s*\n/', trim($template->body)) as $paragraph) {
            if (str_contains($paragraph, '{{action_url}}')) {
                continue; // rendered as the button below
            }
            $mail->line($this->render($paragraph, $data));
        }

        if ($this->actionUrl) {
            $mail->action($this->actionText ?? __('Open WaveTeam Portal'), $this->actionUrl);
        }

        return $mail->salutation(__('Regards,')."\n".config('portal.company.name'));
    }

    /** In-app (database) representation shown on portal dashboards. */
    public function toArray(object $notifiable): array
    {
        $template = $this->template($notifiable);
        $data = ['name' => $notifiable->name ?? ''] + $this->data;
        $body = collect(preg_split('/\n\s*\n/', trim((string) $template?->body)))
            ->reject(fn ($p) => str_contains($p, '{{action_url}}'))
            ->map(fn ($p) => $this->render($p, $data))
            ->implode(' ');

        return [
            'key' => $this->templateKey,
            'title' => $this->render((string) $template?->subject, $data),
            'body' => str_replace('**', '', mb_substr($body, 0, 500)),
            'url' => $this->actionUrl,
            'action' => $this->actionText,
        ];
    }

    /** Plain-text body for SMS / WhatsApp (no Markdown, no links beyond the portal). */
    public function toTextMessage(object $notifiable, string $channel): ?string
    {
        $template = $this->template($notifiable, $channel);
        if (! $template) {
            return null;
        }

        $text = $this->render($template->body, ['name' => $notifiable->name ?? ''] + $this->data);

        return trim(str_replace(['**', '__'], '', $text));
    }

    public function template(object $notifiable, string $channel = 'mail'): ?NotificationTemplate
    {
        $locale = $notifiable->locale ?? app()->getLocale();

        return NotificationTemplate::query()
            ->where('key', $this->templateKey)
            ->where('channel', $channel)
            ->whereIn('locale', array_unique([$locale, 'en']))
            ->orderByRaw('locale = ? desc', [$locale])
            ->first();
    }

    /** @param  array<string, scalar|null>  $data */
    public function render(string $text, array $data): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn ($m) => (string) ($data[$m[1]] ?? ''), $text);
    }

    private function optedOut(object $notifiable, string $channel): bool
    {
        return isset($notifiable->id) && NotificationPreference::query()
            ->where('user_id', $notifiable->id)
            ->where('template_key', $this->templateKey)
            ->where('channel', $channel)
            ->where('enabled', false)
            ->exists();
    }
}
