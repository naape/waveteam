<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Production caches for the portal. Use this instead of "php artisan optimize".
 *
 * When APP_URL has a folder (e.g. https://www.waveteam.lk/app) the ROUTE cache
 * is skipped: Laravel's cached route matcher cannot resolve the folder's own
 * address ("/app/") and the home page would answer 405. Config, event and view
 * caches are still built; uncached routes cost only a few milliseconds.
 */
class OptimizePortal extends Command
{
    protected $signature = 'portal:optimize';

    protected $description = 'Build production caches (route cache skipped when served from a sub-folder).';

    public function handle(): int
    {
        $folder = trim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');

        $this->callSilently('optimize:clear');
        $this->components->task('Caching config', fn () => $this->callSilently('config:cache') === 0);
        $this->components->task('Caching events', fn () => $this->callSilently('event:cache') === 0);
        $this->components->task('Caching views', fn () => $this->callSilently('view:cache') === 0);

        if ($folder === '') {
            $this->components->task('Caching routes', fn () => $this->callSilently('route:cache') === 0);
        } else {
            $this->components->twoColumnDetail('Route cache', "<fg=yellow>skipped (served from /{$folder})</>");
        }

        $this->callSilently('filament:optimize');

        return self::SUCCESS;
    }
}
