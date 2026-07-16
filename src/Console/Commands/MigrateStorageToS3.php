<?php

namespace Sendtrap\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Models\Attachment;
use Sendtrap\Core\Models\Message;

/**
 * One-time copy of existing message/attachment files from the local disk up
 * to S3, for use when switching FILESYSTEM_DISK from local to s3 on a server
 * that already has captured mail. Safe to re-run — skips anything already
 * present on S3.
 */
class MigrateStorageToS3 extends Command
{
    protected $signature = 'storage:migrate-to-s3 {--dry-run : Report what would be migrated without copying anything}';

    protected $description = 'Copy existing local-disk message/attachment files up to S3';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $failures = $this->migrate('message', Message::query()->cursor(), 'raw_path', $dryRun)
            + $this->migrate('attachment', Attachment::query()->cursor(), 'path', $dryRun);

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function migrate(string $label, iterable $records, string $pathColumn, bool $dryRun): int
    {
        $local = Storage::disk('local');
        $s3 = Storage::disk('s3');

        $migrated = 0;
        $alreadyThere = 0;
        $missingLocally = 0;
        $failed = 0;

        foreach ($records as $record) {
            $path = $record->{$pathColumn};

            if ($s3->exists($path)) {
                $alreadyThere++;

                continue;
            }

            if (! $local->exists($path)) {
                $missingLocally++;

                continue;
            }

            if ($dryRun) {
                $migrated++;

                continue;
            }

            try {
                if ($s3->put($path, $local->get($path))) {
                    $migrated++;
                } else {
                    $this->error("Failed to migrate {$label} {$record->id} ({$path}): put() returned false");
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->error("Failed to migrate {$label} {$record->id} ({$path}): {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info(
            ($dryRun ? '[dry-run] ' : '')."{$label}s: {$migrated} migrated, {$alreadyThere} already on s3, "
            ."{$missingLocally} missing locally, {$failed} failed."
        );

        return $missingLocally + $failed;
    }
}
