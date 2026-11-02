<?php

namespace App\Notifications\Channels;

class SmsChannel extends TextMessageChannel
{
    protected function name(): string
    {
        return 'sms';
    }
}
