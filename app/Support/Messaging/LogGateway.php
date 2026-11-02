<?php

namespace App\Support\Messaging;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Development / pre-integration gateway: writes messages to the log instead
 * of sending them. The phone number is partially masked.
 */
class LogGateway implements TextMessageGateway
{
    public function send(string $channel, string $to, string $text): ?string
    {
        Log::info("[{$channel}] to ".Str::mask($to, '*', 4, -3).': '.$text);

        return 'log-'.Str::uuid();
    }
}
