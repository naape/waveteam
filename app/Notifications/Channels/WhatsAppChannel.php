<?php

namespace App\Notifications\Channels;

class WhatsAppChannel extends TextMessageChannel
{
    protected function name(): string
    {
        return 'whatsapp';
    }
}
