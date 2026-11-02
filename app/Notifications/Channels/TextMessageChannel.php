<?php

namespace App\Notifications\Channels;

use App\Support\Messaging\TextMessageGateway;
use App\Support\PhoneNumber;
use Illuminate\Notifications\Notification;
use RuntimeException;

/**
 * Base for SMS / WhatsApp notification channels. The notification provides
 * toTextMessage($notifiable, $channel); the notifiable provides the number
 * via routeNotificationFor('sms'|'whatsapp').
 */
abstract class TextMessageChannel
{
    abstract protected function name(): string;

    public function __construct(private readonly TextMessageGateway $gateway) {}

    public function send(object $notifiable, Notification $notification): ?string
    {
        $to = PhoneNumber::normalize($notifiable->routeNotificationFor($this->name(), $notification));
        if (! $to || ! method_exists($notification, 'toTextMessage')) {
            return null;
        }

        $text = $notification->toTextMessage($notifiable, $this->name());
        if ($text === null || $text === '') {
            return null;
        }

        if (mb_strlen($text) > 1000) {
            throw new RuntimeException('Text message too long.');
        }

        return $this->gateway->send($this->name(), $to, $text);
    }
}
