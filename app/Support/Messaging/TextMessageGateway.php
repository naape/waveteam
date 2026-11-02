<?php

namespace App\Support\Messaging;

/**
 * Outbound SMS / WhatsApp provider. Swap the implementation (Dialog, Mobitel,
 * Twilio, WhatsApp Business Cloud API…) by binding a different class in
 * AppServiceProvider — no notification code changes.
 */
interface TextMessageGateway
{
    /**
     * @param  string  $channel  "sms" or "whatsapp"
     * @return string|null provider message id
     */
    public function send(string $channel, string $to, string $text): ?string;
}
