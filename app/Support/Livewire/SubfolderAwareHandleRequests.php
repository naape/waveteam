<?php

namespace App\Support\Livewire;

use Livewire\Mechanisms\HandleRequests\HandleRequests;

/**
 * Livewire builds its update URL without the folder the app is served from
 * (e.g. "/livewire/update" instead of "/app/livewire/update"), which breaks
 * every Livewire/Filament action when the portal runs at www.waveteam.lk/app.
 * This adds the request's base path back. At a domain root it changes nothing.
 */
class SubfolderAwareHandleRequests extends HandleRequests
{
    public function getUpdateUri()
    {
        return self::basePath().parent::getUpdateUri();
    }

    public static function basePath(): string
    {
        return app()->runningInConsole() ? rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/') : rtrim(request()->getBaseUrl(), '/');
    }
}
