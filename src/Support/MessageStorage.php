<?php

namespace Sendtrap\Core\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Single point of truth for where raw message/attachment bytes live, so the
 * disk (local vs s3) is driven entirely by FILESYSTEM_DISK.
 */
class MessageStorage
{
    public static function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.default'));
    }
}
