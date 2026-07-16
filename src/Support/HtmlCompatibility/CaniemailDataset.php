<?php

namespace Sendtrap\Core\Support\HtmlCompatibility;

use Illuminate\Support\Facades\Cache;

/**
 * The vendored caniemail.com feature-support dataset (resources/data/caniemail/features.json),
 * refreshed offline via `php artisan htmlcheck:sync-data` — never fetched at request time.
 */
class CaniemailDataset
{
    protected static ?array $payload = null;

    /**
     * All features, keyed by slug.
     *
     * @return array<string, array>
     */
    public static function features(): array
    {
        return Cache::rememberForever('htmlcheck:caniemail-features', function () {
            return collect(static::payload()['data'])->keyBy('slug')->all();
        });
    }

    /** Dataset version string (the upstream last-updated timestamp), used to invalidate stale checks. */
    public static function version(): string
    {
        return (string) static::payload()['last_update_date'];
    }

    protected static function payload(): array
    {
        if (static::$payload !== null) {
            return static::$payload;
        }

        $path = resource_path('data/caniemail/features.json');

        return static::$payload = json_decode(file_get_contents($path), true);
    }
}
