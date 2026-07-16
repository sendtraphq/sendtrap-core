<?php

namespace Sendtrap\Core\Support;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for Postmark's free SpamCheck (SpamAssassin) API.
 * https://spamcheck.postmarkapp.com/doc/
 *
 * Runs on demand (from the Spam Analysis tab), not on ingestion — each call
 * takes several seconds, so results are cached on the message afterwards.
 */
class SpamCheck
{
    /**
     * @return array{score: float, report: string}|null null on failure/disabled
     */
    public static function check(string $raw): ?array
    {
        if (! config('services.spamcheck.enabled', true)) {
            return null;
        }

        try {
            $resp = Http::acceptJson()
                ->timeout((int) config('services.spamcheck.timeout', 25))
                ->post(config('services.spamcheck.url'), [
                    'email' => $raw,
                    'options' => 'long',
                ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! $resp->successful() || $resp->json('success') !== true) {
            return null;
        }

        return [
            'score' => (float) $resp->json('score', 0),
            'report' => trim((string) $resp->json('report', '')),
        ];
    }

    /** SpamAssassin's conventional spam threshold. */
    public static function threshold(): float
    {
        return (float) config('services.spamcheck.threshold', 5.0);
    }
}
