<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-configurable settings stored in the `settings` table, cached.
 * Defaults live in config/portal.php so the app works before seeding.
 */
class Settings
{
    private const CACHE_KEY = 'portal.settings';

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        // Keys contain dots (e.g. pcc.reminder_days), so read the defaults
        // array directly instead of via config() dot-notation.
        return $default ?? (config('portal.defaults')[$key] ?? null);
    }

    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, function () {
            if (! Schema::hasTable('settings')) {
                return [];
            }

            return Setting::query()->pluck('value', 'key')->all();
        });
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
