<?php

namespace Sendtrap\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Refresh the vendored caniemail.com feature-support dataset used by HTML
 * Check. Run manually/by a maintainer — never called at request time.
 *
 * Data (c) caniemail (caniemail.com, github.com/shellscape/caniemail),
 * licensed CC-BY-4.0 — see the NOTICE file for the full attribution.
 */
class SyncCaniemailData extends Command
{
    protected $signature = 'htmlcheck:sync-data {--url= : Override the source JSON URL}';

    protected $description = 'Download the latest caniemail.com HTML/CSS support dataset';

    protected const SOURCE_URL = 'https://raw.githubusercontent.com/shellscape/caniemail/main/data/caniemail.json';

    protected const TARGET_PATH = 'data/caniemail/features.json';

    public function handle(): int
    {
        $url = $this->option('url') ?: self::SOURCE_URL;

        $response = Http::timeout(30)->get($url);

        if ($response->failed()) {
            $this->error("Failed to fetch caniemail data from {$url} (HTTP {$response->status()}).");

            return self::FAILURE;
        }

        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['data'], $payload['last_update_date'])) {
            $this->error('Fetched payload does not look like a caniemail dataset (missing data/last_update_date).');

            return self::FAILURE;
        }

        $path = resource_path(self::TARGET_PATH);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $response->body());

        $this->info(sprintf(
            'Synced %d features (dataset dated %s) to %s',
            count($payload['data']),
            $payload['last_update_date'],
            self::TARGET_PATH,
        ));

        return self::SUCCESS;
    }
}
